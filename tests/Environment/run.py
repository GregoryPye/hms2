#!/usr/bin/env python3
"""Isolated backend, frontend-component and browser tooling profiles; no production deployment."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import tarfile
import tempfile
import threading
import time
import urllib.request
import uuid
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
CACHE = HERE / '.cache'
PROFILES = ('backend', 'frontend', 'browser')
DOCKER = ['docker', '--host=unix:///var/run/docker.sock']
DOCKER_ENV = {k: v for k, v in os.environ.items() if k not in {
    'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'}}
IMAGES = json.loads((HERE / 'images.json').read_text())
PROXIES = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
           'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy']
HARNESS_FILES = ['run.py', 'smoke.php', 'images.json', 'php.Dockerfile', 'playwright-seccomp.json',
                 'guard.cjs', 'test_runner.py', 'frontend/package.json', 'frontend/package-lock.json',
                 'frontend/smoke.test.cjs', 'browser/package.json', 'browser/package-lock.json', 'browser/browser.cjs']
REGRESSION_FILES = [
    'tests/Support/Environment.php', 'tests/Support/Fixtures.php', 'tests/Support/Clock.php',
    'tests/Support/TransportGuard.php', 'tests/Support/DatabaseSandbox.php', 'tests/Support/run.php',
    'tests/regression.xml', 'tests/Foundation/Unit/SupportTest.php',
    'tests/Foundation/Integration/DatabaseSupportTest.php', 'tests/Support/SqlTestCase.php',
    'tests/Integration/Tools/Sql/StatusAccessTest.php', 'tests/Integration/Tools/Sql/InductionTest.php',
    'tests/Integration/Tools/Sql/ChargingTest.php', 'tests/Integration/Tools/Sql/ReportingTest.php',
    'tests/Support/BackendRuleTestCase.php', 'tests/Support/BackendDatabaseTestCase.php',
    'tests/Support/BackendLifecycleTestCase.php',
    'tests/Unit/Tools/ToolRulesTest.php', 'tests/Unit/Tools/BookingRulesTest.php',
    'tests/Unit/Tools/ReportingContractsTest.php', 'tests/Integration/Tools/Backend/RepositoryTest.php',
    'tests/Integration/Tools/Backend/LifecycleAuditTest.php', 'tests/Integration/Tools/Backend/MembershipRetentionTest.php',
    'tests/Integration/Tools/Backend/ReportingWorkflowTest.php',
    'tests/Support/HttpTestCase.php', 'tests/Feature/Tools/AuthenticationTest.php',
    'tests/Feature/Tools/BookingHttpTest.php', 'tests/Feature/Tools/ToolPagesHttpTest.php', 'tests/Feature/Tools/ToolAccessHttpTest.php',
    'tests/Feature/Tools/MemberSearchHttpTest.php',
]
ENV = {
    'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    'HOME': '/tmp', 'APP_ENV': 'testing', 'APP_DEBUG': 'false',
    'APP_KEY': 'base64:' + 'dGVzdC1vbmx5LWtleS1uZXZlci1wcm9kdWN0aW9uISE=',
    'APP_URL': 'http://hms.test', 'LOG_CHANNEL': 'stderr', 'LOG_LEVEL': 'error',
    'DB_CONNECTION': 'mysql', 'DB_HOST': 'mysql', 'DB_PORT': '3306',
    'DB_DATABASE': 'hms_smoke', 'DB_USERNAME': 'hms_smoke', 'DB_PASSWORD': 'test-only',
    'DOCTRINE_METADATA': 'yaml', 'DOCTRINE_PROXY_AUTOGENERATE': 'true',
    'DOCTRINE_CACHE': 'array', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
    'MAIL_MAILER': 'array', 'QUEUE_CONNECTION': 'hms_smoke_null', 'BROADCAST_CONNECTION': 'log',
    'PASSWORDSTORE': 'doctrine', 'TELESCOPE_ENABLED': 'false', 'PULSE_ENABLED': 'false',
    'FEATURE_DISCORD': 'false', 'FEATURE_SLACK': 'false',
    'PLAYWRIGHT_BROWSERS_PATH': '/ms-playwright', 'CI': 'true',
}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def command(args, *, timeout=120, check=True, **kwargs):
    result = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            timeout=timeout, **kwargs)
    if check and result.returncode:
        raise RuntimeError(f'Command failed ({result.returncode}): {args[:3]}\n{result.stdout[-4000:]}')
    return result


def docker(*args, **kwargs):
    return command(DOCKER + list(args), env=DOCKER_ENV, **kwargs)


def write_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(data, indent=2) + '\n')
    temporary.replace(path)


def digest(data):
    return hashlib.sha256(json.dumps(data, sort_keys=True).encode()).hexdigest()


def file_hash(name):
    path = ROOT / name
    require(path.is_file() and not path.is_symlink(), 'Missing or unsafe source: ' + name)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def product_files():
    files = []
    for name in command(['git', 'ls-files', '-z'], cwd=ROOT).stdout.split('\0'):
        p = Path(name)
        if not name or (p.parts[0] not in {'app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'public'}
                        and name not in {'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml'}):
            continue
        if p.parts[:2] in [('bootstrap', 'cache'), ('database', 'database')]:
            continue
        if any(x.startswith('.') or x in {'vendor', 'node_modules', 'storage', 'artifacts'} for x in p.parts):
            continue
        if p.suffix.lower() in {'.key', '.pem', '.p12', '.pfx'} or p.name in {'auth.json', 'users.json'}:
            continue
        if (ROOT / name).is_file():
            files.append(name)
    return sorted(files)


def inputs(profile):
    products = {name: file_hash(name) for name in product_files()}
    tree = command(['git', 'ls-tree', 'HEAD', 'database/database'], cwd=ROOT).stdout
    baseline = {'commit': command(['git', 'rev-parse', 'HEAD'], cwd=ROOT).stdout.strip(),
                'submodule': re.search(r'commit ([a-f0-9]{40})', tree).group(1), 'product_digest': digest(products)}
    # The common tooling identity prevents aggregation of results from different harness revisions.
    tooling = {name: file_hash(name) for name in REGRESSION_FILES + ['tests/Environment/' + n for n in HARNESS_FILES]}
    selected = dict(products) if profile == 'backend' else {}
    if profile == 'backend':
        tests = [n for n in command(['git', 'ls-files', '-z', 'tests'], cwd=ROOT).stdout.split('\0')
                 if n and not n.startswith('tests/Environment/') and Path(n).suffix in {'.php', '.xml'}]
        selected.update({name: file_hash(name) for name in tests + REGRESSION_FILES + ['tests/Environment/smoke.php']})
    elif profile == 'frontend':
        name = 'resources/js/components/Tools/ToolGrantModal.vue'
        selected[name] = products[name]
    return {'profile': profile, 'baseline': baseline, 'tooling_digest': digest(tooling),
            'source_hashes': selected, 'tooling_hashes': tooling}


def ownership():
    path = CACHE / 'ownership.json'
    return json.loads(path.read_text()) if path.exists() else {'images': [], 'volumes': [], 'build_cache': []}


def own(kind, item):
    state = ownership()
    if item not in state[kind]:
        state[kind].append(item)
    write_json(CACHE / 'ownership.json', state)


def build_cache_ids():
    result = docker('buildx', 'du', '--format=json')
    return {json.loads(line)['ID'] for line in result.stdout.splitlines() if line.startswith('{')}


def clean(profile, volumes_only=False):
    """Evict only resources allocated and recorded by this harness; preserve borrowed resources."""
    state = ownership()
    selected = lambda x: profile == 'all' or x['profile'] == profile
    for item in list(state['volumes']):
        if not selected(item):
            continue
        result = docker('volume', 'inspect', item['name'], check=False)
        if result.returncode == 0:
            labels = json.loads(result.stdout)[0].get('Labels') or {}
            require(labels.get('hms2.harness.profile') == item['profile'], 'Refuse foreign volume cleanup')
            docker('volume', 'rm', item['name'])
        state['volumes'].remove(item)
    if volumes_only:
        write_json(CACHE / 'ownership.json', state)
        return
    for item in list(reversed(state['images'])):
        if not selected(item):
            continue
        result = docker('image', 'inspect', item['ref'], check=False)
        if result.returncode == 0:
            require(json.loads(result.stdout)[0]['Id'] == item['id'], 'Image reference changed; refuse cleanup')
            docker('image', 'rm', item['ref'])
        state['images'].remove(item)
    cache_candidates = [item for item in state['build_cache'] if selected(item)]
    for item in cache_candidates:
        if selected(item):
            # ID filtering is supported by Buildx; never prune the shared builder globally.
            docker('buildx', 'prune', '--all', '--force', '--filter', 'id=' + item['id'])
            state['build_cache'].remove(item)
    if cache_candidates:
        remaining = build_cache_ids()
        # A referenced cache may not yet be reclaimable. Keep its ownership for the next cleanup.
        for item in cache_candidates:
            if item['id'] in remaining:
                state['build_cache'].append(item)
    write_json(CACHE / 'ownership.json', state)


def ensure_image(key, profile):
    ref = IMAGES[key]
    if docker('image', 'inspect', ref, check=False).returncode:
        tag = ref.split('@')[0]
        tag_existed = docker('image', 'inspect', tag, check=False).returncode == 0
        print('Preparing pinned ' + key + ' image', flush=True)
        result = docker('pull', ref, timeout=900, check=False)
        if docker('image', 'inspect', ref, check=False).returncode == 0:
            image_id = docker('image', 'inspect', ref, '--format', '{{.Id}}').stdout.strip()
            own('images', {'profile': profile, 'ref': ref, 'id': image_id})
            if not tag_existed:
                own('images', {'profile': profile, 'ref': tag, 'id': image_id})
        require(result.returncode == 0, 'Image preparation failed: ' + key + '\n' + result.stdout[-2000:])
    return ref


def readable_tree(root):
    root.chmod(0o755)
    for path in root.rglob('*'):
        path.chmod(0o755 if path.is_dir() else 0o644)


def copy_file(source, target):
    require(source.is_file() and not source.is_symlink(), 'Unsafe preparation input')
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)


def backend_runtime(ca):
    fingerprint = digest({'recipe': file_hash('tests/Environment/php.Dockerfile'),
                          'php': IMAGES['php'], 'composer': IMAGES['composer']})[:16]
    tag = 'hms2-tests-php:' + fingerprint
    if docker('image', 'inspect', tag, check=False).returncode == 0:
        return tag
    php = ensure_image('php', 'backend')
    composer = ensure_image('composer', 'backend')
    before = build_cache_ids()
    try:
        with tempfile.TemporaryDirectory(prefix='hms-runtime-build-') as temp:
            copy_file(HERE / 'php.Dockerfile', Path(temp) / 'php.Dockerfile')
            result = docker('build', '--secret', 'id=ca,src=' + str(ca), '--build-arg', 'PHP_IMAGE=' + php,
                            '--build-arg', 'COMPOSER_IMAGE=' + composer, '-f', str(Path(temp) / 'php.Dockerfile'),
                            '-t', tag, temp, timeout=1800, check=False)
            (CACHE / 'backend-runtime-build.log').write_text(result.stdout)
            require(result.returncode == 0, 'PHP runtime build failed; see backend-runtime-build.log')
        image_id = docker('image', 'inspect', tag, '--format', '{{.Id}}').stdout.strip()
        own('images', {'profile': 'backend', 'ref': tag, 'id': image_id})
    finally:
        for cache_id in build_cache_ids() - before:
            own('build_cache', {'profile': 'backend', 'id': cache_id})
    return tag


def populate_source(profile, spec, dest):
    if profile == 'backend':
        for name in spec['source_hashes']:
            copy_file(ROOT / name, dest / name)
        for name in ['bootstrap/cache', 'storage', 'database/factories', 'database/seeds']:
            (dest / name).mkdir(parents=True, exist_ok=True)
        require(not list(dest.rglob('.env*')), 'Environment file found in filtered source')
        # Deployment normally supplies this file; derive a synthetic immutable one from
        # the reviewed snapshot, without copying .git or invoking git from HTTP views.
        (dest / 'version').write_text(spec['baseline']['commit'] + '\n')
        revision = spec['baseline']['submodule']
        archive_path = CACHE / ('sql-' + revision + '.tar.gz')
        if not archive_path.exists():
            with urllib.request.urlopen('https://codeload.github.com/NottingHack/database/tar.gz/' + revision, timeout=45) as response:
                archive_path.write_bytes(response.read(16 * 1024 * 1024))
        with tarfile.open(archive_path, mode='r:gz') as archive:
            for member in archive.getmembers():
                parts = Path(member.name).parts[1:]
                if member.isfile() and parts and '..' not in parts and parts[0] in {'procedures', 'views'} and parts[-1].endswith('.sql'):
                    path = dest / 'database/database' / Path(*parts)
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(archive.extractfile(member).read())
    else:
        for name in ['package.json', 'package-lock.json']:
            copy_file(HERE / profile / name, dest / name)
        copy_file(HERE / 'guard.cjs', dest / 'guard.cjs')
        if profile == 'frontend':
            copy_file(HERE / 'frontend/smoke.test.cjs', dest / 'smoke.test.cjs')
            copy_file(ROOT / 'resources/js/components/Tools/ToolGrantModal.vue', dest / 'ToolGrantModal.vue')
        else:
            copy_file(HERE / 'browser/browser.cjs', dest / 'browser.cjs')
    readable_tree(dest)


def manifest_ready(manifest):
    expected = 'hms-tests-' + manifest['profile'] + '-' + digest(manifest['inputs'])[:16]
    if manifest['volume'] != expected:
        return False
    volume = docker('volume', 'inspect', manifest['volume'], check=False)
    if volume.returncode or (json.loads(volume.stdout)[0].get('Labels') or {}).get('hms2.harness.profile') != manifest['profile']:
        return False
    return (all(docker('image', 'inspect', ref, check=False).returncode == 0 for ref in manifest['images'].values())
            and docker('volume', 'inspect', manifest['volume'], check=False).returncode == 0)


def prepare(profile):
    CACHE.mkdir(parents=True, exist_ok=True)
    docker('info', '--format', '{{.Driver}}')
    # Bounded profile cache: other harness-owned profiles are evicted, borrowed images are preserved.
    for other in PROFILES:
        if other != profile:
            clean(other)
    spec = inputs(profile)
    path = CACHE / (profile + '.json')
    if path.exists():
        previous = json.loads(path.read_text())
        if previous['inputs'] == spec and manifest_ready(previous):
            print(profile + ': reusing matching prepared profile', flush=True)
            return previous
    clean(profile, volumes_only=True)
    ca = Path(os.environ.get('SSL_CERT_FILE', '/etc/ssl/certs/ca-certificates.crt'))
    require(ca.is_file(), 'Trusted preparation CA bundle required')
    # Capture before image preparation too: BuildKit imports local parent-image records.
    cache_before = build_cache_ids() if profile == 'backend' else None
    try:
        probe = ensure_image('probe', 'shared')
        images = {'probe': probe}
        if profile == 'backend':
            images['runtime'] = backend_runtime(ca)
            images['mysql'] = ensure_image('mysql', profile)
        else:
            images['runtime'] = ensure_image('node' if profile == 'frontend' else 'browser', profile)
        volume = 'hms-tests-' + profile + '-' + digest(spec)[:16]
        require(docker('volume', 'inspect', volume, check=False).returncode != 0, 'Refuse unregistered preparation volume')
        own('volumes', {'profile': profile, 'name': volume})
        docker('volume', 'create', '--label', 'hms2.harness.profile=' + profile, volume)
        helper = 'hms-prepare-' + uuid.uuid4().hex[:10]
        install = helper + '-install'
        try:
            with tempfile.TemporaryDirectory(prefix='hms-profile-source-') as temp:
                dest = Path(temp)
                populate_source(profile, spec, dest)
                # Preparation helper only: no network, no application execution or host mount.
                docker('create', '--pull=never', '--name', helper, '--network', 'none', '--read-only',
                       '--cap-drop=ALL', '--cap-add=CHOWN', '--mount', f'type=volume,src={volume},dst=/bundle',
                       probe, 'chown', '-R', '1000:1000', '/bundle')
                docker('cp', str(dest) + '/.', helper + ':/bundle/')
                docker('start', '-a', helper)
                docker('rm', '-fv', helper)
            mountpoint = '/app' if profile == 'backend' else '/tools'
            args = ['run', '--pull=never', '--name', install, '--read-only', '--cap-drop=ALL',
                    '--security-opt=no-new-privileges', '--user', '1000:1000', '--memory=2g', '--pids-limit=256',
                    '--tmpfs', '/tmp:rw,nosuid,nodev,size=512m,mode=1777',
                    '--mount', f'type=volume,src={volume},dst={mountpoint}',
                    '--mount', f'type=bind,src={ca},dst=/run/proxy-ca.pem,readonly', '-w', mountpoint,
                    '-e', 'HOME=/tmp']
            if profile == 'backend':
                args += ['-e', 'COMPOSER_HOME=/tmp/composer', '-e', 'COMPOSER_CAFILE=/run/proxy-ca.pem',
                         '--entrypoint', 'composer', images['runtime'], 'install', '--no-scripts', '--no-plugins',
                         '--no-interaction', '--prefer-dist', '--no-progress']
            else:
                args += ['-e', 'NODE_EXTRA_CA_CERTS=/run/proxy-ca.pem', '-e', 'NPM_CONFIG_USERCONFIG=/dev/null',
                         '--entrypoint', 'npm', images['runtime'], 'ci', '--ignore-scripts', '--no-audit', '--no-fund']
            print(profile + ': installing locked public dependencies; scripts/plugins disabled', flush=True)
            result = docker(*args, timeout=900, check=False)
            (CACHE / (profile + '-install.log')).write_text(result.stdout)
            require(result.returncode == 0, profile + ' dependency preparation failed: ' + result.stdout[-2000:])
        except BaseException:
            docker('rm', '-fv', helper, install, check=False)
            clean(profile)
            raise
        finally:
            docker('rm', '-fv', helper, install, check=False)
        manifest = {'profile': profile, 'inputs': spec, 'images': images, 'volume': volume,
                    'runtime_image_id': docker('image', 'inspect', images['runtime'], '--format', '{{.Id}}').stdout.strip()}
        write_json(path, manifest)
        print(profile + ': preparation complete; no application installation scripts ran', flush=True)
        return manifest
    finally:
        if cache_before is not None:
            for cache_id in build_cache_ids() - cache_before:
                own('build_cache', {'profile': profile, 'id': cache_id})


def parse_memory(value):
    match = re.match(r'([0-9.]+)([A-Za-z]+)', value.strip())
    if not match:
        return 0
    return int(float(match[1]) * {'B': 1, 'KiB': 1024, 'MiB': 1024**2, 'GiB': 1024**3,
                                'kB': 1000, 'MB': 1000**2, 'GB': 1000**3}.get(match[2], 1))


class Resources:
    """Global filesystem samples include retained caches; memory is observed, not a precise peak."""
    def __init__(self, containers=None):
        self.containers = containers if containers is not None else []
        disk = shutil.disk_usage(ROOT)
        self.data = {'disk_total_bytes': disk.total, 'disk_used_start_bytes': disk.used,
                     'disk_used_peak_bytes': disk.used, 'disk_free_min_bytes': disk.free,
                     'observed_container_memory_peak_bytes': 0, 'memory_samples': 0, 'disk_sampling_seconds': 0.25}
        self.stop = threading.Event()
        self.thread = threading.Thread(target=self.sample, daemon=True)
        self.thread.start()

    def sample(self):
        counter = 0
        while not self.stop.is_set():
            disk = shutil.disk_usage(ROOT)
            self.data['disk_used_peak_bytes'] = max(disk.used, self.data['disk_used_peak_bytes'])
            self.data['disk_free_min_bytes'] = min(disk.free, self.data['disk_free_min_bytes'])
            counter += 1
            if counter % 8 == 0 and self.containers:
                try:
                    names = list(self.containers)
                    result = docker('stats', '--no-stream', '--format', '{{.MemUsage}}', *names,
                                    check=False, timeout=4)
                    if result.returncode == 0:
                        memory = sum(parse_memory(line.split('/')[0]) for line in result.stdout.splitlines())
                        self.data['observed_container_memory_peak_bytes'] = max(memory, self.data['observed_container_memory_peak_bytes'])
                        self.data['memory_samples'] += 1
                except (RuntimeError, subprocess.TimeoutExpired):
                    pass
            self.stop.wait(0.25)

    def finish(self):
        self.stop.set()
        self.thread.join(timeout=5)
        self.data['disk_used_end_bytes'] = shutil.disk_usage(ROOT).used
        self.data['disk_incremental_peak_bytes'] = max(0, self.data['disk_used_peak_bytes'] - self.data['disk_used_start_bytes'])
        return self.data


def extract_artifacts(text, destination, required):
    destination.mkdir(parents=True, exist_ok=True)
    found, messages = set(), []
    for line in text.splitlines():
        if line.startswith('HMS_ARTIFACT:'):
            _, filename, encoded = line.split(':', 2)
            require(filename in {'junit.xml', 'clover.xml', 'jest.json', 'browser.json'} and filename not in found,
                    'Unexpected/duplicate artifact name')
            require(len(encoded) <= 24 * 1024 * 1024, 'Oversize encoded artifact')
            content = base64.b64decode(encoded, validate=True)
            require(len(content) <= 16 * 1024 * 1024, 'Oversize report')
            path = destination / filename
            require(not path.is_symlink(), 'Unsafe artifact destination')
            path.write_bytes(content)
            found.add(filename)
        else:
            messages.append(line)
    return '\n'.join(messages) + '\n', sorted(set(required) - found)


def junit_summary(path):
    """Count testcase leaves once; preserve failing contracts rather than bless legacy defects."""
    cases = list(ET.parse(path).getroot().iter('testcase'))
    require(bool(cases), 'JUnit report contains no test cases')
    summary = {'tests': len(cases), 'assertions': sum(int(c.get('assertions', '0')) for c in cases),
               'passed': 0, 'failures': 0, 'errors': 0, 'skipped': 0, 'nonpassing': []}
    for case in cases:
        issues = [child for child in case if child.tag in {'failure', 'error', 'skipped'}]
        if not issues:
            summary['passed'] += 1
        for issue in issues:
            summary[{'failure': 'failures', 'error': 'errors', 'skipped': 'skipped'}[issue.tag]] += 1
            summary['nonpassing'].append({'test': case.get('class', '') + '::' + case.get('name', ''),
                                          'kind': issue.tag, 'detail': (issue.text or issue.get('message', ''))[:2000]})
    return summary


def coverage_summary(paths):
    """Union Clover statement lines, retaining uncovered files and avoiding repeat inflation."""
    files = {}
    for path in paths:
        require(path.is_file() and not path.is_symlink() and path.stat().st_size <= 16 * 1024 * 1024,
                'Missing/unsafe coverage artifact')
        for entry in ET.parse(path).getroot().iter('file'):
            name = entry.get('name', '')
            require(name.startswith('/app/app/') and '..' not in Path(name).parts, 'Unexpected coverage source')
            name = name.removeprefix('/app/')
            lines = files.setdefault(name, {})
            for line in entry.findall('line'):
                if line.get('type') == 'stmt':
                    number = int(line.get('num'))
                    lines[number] = lines.get(number, False) or int(line.get('count', '0')) > 0
    def totals(selected):
        count = sum(len(lines) for name, lines in files.items() if selected(name))
        covered = sum(sum(lines.values()) for name, lines in files.items() if selected(name))
        return {'covered': covered, 'executable': count,
                'percent': round(100 * covered / count, 2) if count else None}
    return {'basis': 'Clover statement-line union; includes failing tests; no branch/SQL/JS coverage',
            'reports': len(paths), 'source_files': len(files),
            'core': totals(lambda name: name.startswith(('app/HMS/Tools/', 'app/HMS/Factories/Tools/'))),
            'full': totals(lambda name: True),
            'files': {name: {'covered': sum(lines.values()), 'executable': len(lines)}
                      for name, lines in sorted(files.items())}}


class ProfileRun:
    def __init__(self, manifest, cycles, output, regression, suite='all'):
        self.manifest, self.profile = manifest, manifest['profile']
        self.cycles, self.output, self.regression, self.suite = cycles, output, regression, suite
        self.containers, self.networks, self.workers, self.rows = [], [], {}, []
        self.completed = 0
        self.output.mkdir(parents=True, exist_ok=True)
        self.report = {'action': 'pr-check' if regression else 'smoke', 'profile': self.profile, 'baseline': manifest['inputs']['baseline'],
                       'tooling_digest': manifest['inputs']['tooling_digest'], 'outcome': 'incomplete',
                       'requested_cycles': cycles, 'completed_cycles': 0, 'results': self.rows, 'suite': suite,
                       'scope': 'Delivered foundation, SQL, backend PHP, routed HTTP regression and tooling; chunks 2E–2G remain planned.'}

    def record(self, name, status, **more):
        self.rows.append({'cycle': self.cycle, 'check': name, 'status': status, **more})
        print(f'{self.profile} cycle {self.cycle}: {name}: {status}', flush=True)
        return status == 'pass'

    def runtime_env(self, extra=None):
        values = dict(ENV) if self.profile == 'backend' else {
            'PATH': ENV['PATH'], 'HOME': '/tmp', 'APP_ENV': 'testing', 'CI': 'true',
            'PLAYWRIGHT_BROWSERS_PATH': '/ms-playwright'}
        values.update({'HMS_TEST_PROFILE': self.profile, 'HMS_TEST_CYCLE': str(self.cycle)})
        values.update(extra or {})
        return [key + '=' + value for key, value in values.items()]

    def remove(self, name):
        docker('rm', '-fv', name, check=False, timeout=30)
        if name in self.containers:
            self.containers.remove(name)
        for key, value in list(self.workers.items()):
            if value == name:
                del self.workers[key]

    def inspect_worker(self, name, network, mounts):
        data = json.loads(docker('inspect', name).stdout)[0]
        host = data['HostConfig']
        require(host['ReadonlyRootfs'] and not host['Privileged'] and host['CapDrop'] == ['ALL'], 'Unsafe container privilege/rootfs')
        require(not host['Binds'] and not host['PortBindings'] and host['NetworkMode'] == network, 'Unexpected host/network resource')
        require(data['Config']['User'] == '1000:1000' and 'no-new-privileges' in host['SecurityOpt'], 'Unsafe user/privilege setting')
        require(len(data['Mounts']) == len(mounts) and all(
            m['Type'] == 'volume' and not m['RW'] and (m['Name'], m['Destination']) in mounts
            for m in data['Mounts']), 'Unexpected writable/source mount')

    def worker(self, network):
        if network in self.workers:
            return self.workers[network]
        name = self.prefix + '-worker-' + str(len(self.workers)) + '-' + uuid.uuid4().hex[:4]
        self.containers.append(name)
        point = '/app' if self.profile == 'backend' else '/tools'
        args = ['run', '--pull=never', '-d', '--name', name, '--network', network, '--read-only',
                '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user', '1000:1000',
                '--pids-limit=256', '--memory=2g', '--entrypoint', '/usr/bin/env',
                '--tmpfs', '/tmp:rw,nosuid,nodev,size=512m,mode=1777',
                '--mount', f'type=volume,src={self.manifest["volume"]},dst={point},readonly', '-w', point]
        if self.profile == 'backend':
            args += ['--tmpfs', '/app/storage:rw,nosuid,nodev,size=128m,mode=1777',
                     '--tmpfs', '/app/bootstrap/cache:rw,nosuid,nodev,size=16m,mode=1777']
        if self.profile == 'browser':
            args += ['--security-opt', 'seccomp=' + str(HERE / 'playwright-seccomp.json')]
        for proxy in PROXIES:
            args += ['-e', proxy + '=']
        args += [self.manifest['images']['runtime'], '-i', 'PATH=' + ENV['PATH'], 'tail', '-f', '/dev/null']
        docker(*args)
        self.inspect_worker(name, network, [(self.manifest['volume'], point)])
        self.workers[network] = name
        return name

    def case(self, name, args, *, network='none', extra=None, expected=0, timeout=120, artifacts=()):
        worker = self.worker(network)
        result = docker('exec', '--user', '1000:1000', worker, '/usr/bin/env', '-i',
                        *self.runtime_env(extra), *args, timeout=timeout, check=False)
        text = result.stdout
        missing = []
        if artifacts:
            text, missing = extract_artifacts(text, self.output / f'cycle-{self.cycle}-{name}-artifacts', artifacts)
        if missing:
            text += '\nMissing required artifacts: ' + ', '.join(missing)
        log = f'cycle-{self.cycle}-{name}.log'
        (self.output / log).write_text(text)
        return self.record(name, 'pass' if result.returncode == expected and not missing else 'blocked',
                           exit_code=result.returncode, log=log, artifacts_complete=not missing)

    def network(self, suffix):
        name = self.prefix + '-' + suffix
        docker('network', 'create', '--internal', '--opt', 'com.docker.network.bridge.gateway_mode_ipv4=isolated', name)
        self.networks.append(name)
        data = json.loads(docker('network', 'inspect', name).stdout)[0]
        require(data['Internal'] and data['Options']['com.docker.network.bridge.gateway_mode_ipv4'] == 'isolated', 'Unsafe private bridge')
        return name

    def probe(self):
        network = self.network('canary-net')
        name = self.prefix + '-canary'
        self.containers.append(name)
        docker('run', '--pull=never', '-d', '--name', name, '--network', network, '--read-only',
               '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user', '1000:1000',
               '--memory=32m', '--pids-limit=32', '--tmpfs', '/tmp:rw,nosuid,nodev,size=1m,mode=1777',
               '--entrypoint', '/bin/env', self.manifest['images']['probe'], '-i', 'PATH=/bin',
               'sh', '-c', 'mkdir /tmp/www && echo probe > /tmp/www/index.html && exec httpd -f -p 18080 -h /tmp/www')
        self.inspect_worker(name, network, [])
        ip = json.loads(docker('inspect', name).stdout)[0]['NetworkSettings']['Networks'][network]['IPAddress']
        control = self.prefix + '-control'
        self.containers.append(control)
        result = docker('run', '--pull=never', '--name', control, '--network', network, '--read-only',
                        '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user', '1000:1000',
                        '--memory=32m', '--pids-limit=32', '--entrypoint', '/bin/env',
                        self.manifest['images']['probe'], '-i', 'PATH=/bin', 'wget', '-q', '-O-', '-T', '3',
                        'http://' + ip + ':18080/', check=False)
        self.inspect_worker(control, network, [])
        self.remove(control)
        require(self.record('canary-control', 'pass' if result.returncode == 0 and result.stdout.strip() == 'probe' else 'blocked'),
                'Positive network isolation control failed')
        return ip

    def safety(self, ip, network):
        backend = self.profile == 'backend'
        guard = ['php', 'tests/Environment/smoke.php'] if backend else ['node', '/tools/guard.cjs']
        require(self.case('private-isolation', guard + ['isolation'], network=network, extra={'CANARY_IP': ip}), 'Private isolation failed')
        # Release this container before starting a no-network worker (especially important for Chromium's image).
        self.remove(self.workers[network])
        require(self.case('unit-isolation', guard + ['isolation'], extra={'CANARY_IP': ip}), 'Unit isolation failed')
        for name, extra in [('production', {'APP_ENV': 'production'}), ('database', {'DB_HOST': 'production.invalid'}),
                            ('credential', {'STRIPE_SECRET': 'synthetic-sentinel'}), ('host-sentinel', {'HMS_HOST_SENTINEL': 'synthetic-sentinel'})]:
            require(self.case('reject-' + name, guard + ['preflight'], extra=extra, expected=78), 'Unsafe configuration accepted')
        if backend:
            script = ('file_put_contents("/app/bootstrap/cache/config.php", "<?php return [];\\n"); '
                      'register_shutdown_function(fn()=>unlink("/app/bootstrap/cache/config.php")); '
                      'require "tests/Environment/smoke.php";')
            command_args = ['php', '-r', script]
        else:
            command_args = ['node', '-e', 'const fs=require("node:fs");fs.writeFileSync("/tmp/hms-cached-config","sentinel");'
                            'process.on("exit",()=>fs.unlinkSync("/tmp/hms-cached-config"));require("/tools/guard.cjs").main("preflight");']
        require(self.case('reject-cache', command_args, expected=78), 'Cached configuration accepted')

    def php_suite(self, name, suite, network):
        passed = self.case(name, ['php', '-d', 'pcov.enabled=1', '-d', 'pcov.directory=/app/app',
            'tests/Support/run.php', '--configuration', 'tests/regression.xml', '--testsuite', suite,
            '--fail-on-empty-test-suite', '--display-deprecations', '--do-not-cache-result', '--order-by=' + ('reverse' if self.cycle % 2 == 0 else 'default'),
            '--log-junit', '/tmp/hms-regression/junit.xml', '--coverage-clover', '/tmp/hms-regression/clover.xml', '--coverage-text'],
            network=network, timeout=240, artifacts=('junit.xml', 'clover.xml'))
        row = self.rows[-1]
        try:
            require(row['artifacts_complete'], 'Required PHP reports missing')
            row['tests'] = junit_summary(self.output / f'cycle-{self.cycle}-{name}-artifacts' / 'junit.xml')
            if row['tests']['passed'] != row['tests']['tests']:
                row['status'] = 'blocked'
        except (OSError, ValueError, ET.ParseError, RuntimeError) as error:
            row['status'], row['report_error'] = 'blocked', str(error)
        return passed and row['status'] == 'pass'

    def backend(self, network):
        guard = ['php', 'tests/Environment/smoke.php']
        self.case('php-tooling', guard + ['tooling'])
        self.case('pest-discovery', ['php', 'vendor/bin/pest', '--list-tests'])
        self.case('pest-unit', ['php', 'vendor/bin/pest', 'tests/Unit/ExampleTest.php', '--no-coverage', '--do-not-cache-result'])
        if self.regression and self.suite in {'all', 'backend', 'backend-unit'}:
            self.php_suite('foundation-unit', 'FoundationUnit', 'none')
            self.php_suite('backend-unit', 'BackendUnit', 'none')
        self.remove(self.workers['none'])
        if self.regression and self.suite == 'backend-unit':
            return
        name = self.prefix + '-database'
        self.containers.append(name)
        args = ['run', '--pull=never', '-d', '--name', name, '--network', network, '--network-alias', 'mysql',
                '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user', '999:999',
                '--pids-limit=256', '--memory=2g', '--tmpfs', '/var/lib/mysql:rw,nosuid,nodev,size=1g,uid=999,gid=999',
                '--tmpfs', '/var/run/mysqld:rw,nosuid,nodev,uid=999,gid=999', '--tmpfs', '/tmp:rw,nosuid,nodev,mode=1777',
                '-e', 'MYSQL_ROOT_PASSWORD=test-root-only', '-e', 'MYSQL_DATABASE=hms_smoke',
                '-e', 'MYSQL_USER=hms_smoke', '-e', 'MYSQL_PASSWORD=test-only']
        for proxy in PROXIES:
            args += ['-e', proxy + '=']
        # TODO: Confirm production SQL modes and revisit strict/zero-date migration compatibility.
        args += [self.manifest['images']['mysql'], '--mysql-native-password=ON', '--skip-log-bin',
                 '--sql-mode=ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
                 '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci']
        docker(*args)
        for attempt in range(60):
            result = docker('exec', '-e', 'MYSQL_PWD=test-only', name, 'mysql', '--protocol=TCP',
                            '-h127.0.0.1', '-uhms_smoke', '-Nse', 'SELECT 1', 'hms_smoke', check=False, timeout=10)
            if result.returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Disposable MySQL did not become ready')
        for check in ['mysql', 'application', 'laravel-schema', 'schema', 'fixtures', 'routines']:
            require(self.case(check, guard + [check], network=network, timeout=180), 'Backend prerequisite failed: ' + check)
        if self.regression:
            if self.suite in {'all', 'backend', 'backend-integration'}:
                self.php_suite('foundation-database', 'FoundationDatabase', network)
                for group in ['Repository', 'LifecycleAudit', 'MembershipRetention', 'ReportingWorkflow']:
                    self.php_suite('backend-' + group.lower(), 'Backend' + group, network)
            if self.suite in {'all', 'http'}:
                if self.suite == 'http':
                    self.php_suite('foundation-database', 'FoundationDatabase', network)
                for group in ['Authentication', 'Booking', 'ToolPages', 'ToolAccess', 'MemberSearch']:
                    self.php_suite('http-' + group.lower(), 'Http' + group, network)
            if self.suite in {'all', 'sql'}:
                for group in ['StatusAccess', 'Induction', 'Charging', 'Reporting']:
                    self.php_suite('sql-' + group.lower(), 'Sql' + group, network)

    def cleanup(self):
        for name in list(reversed(self.containers)):
            self.remove(name)
        # Reconcile late Docker create requests after interruption, restricted to this cycle's unique prefix.
        for attempt in range(8):
            late = docker('ps', '-a', '--filter', 'name=^/' + self.prefix + '-', '--format', '{{.Names}}').stdout.splitlines()
            for name in late:
                if name.startswith(self.prefix + '-'):
                    docker('rm', '-fv', name, check=False)
            time.sleep(0.25)
        for network in reversed(self.networks):
            docker('network', 'rm', network)
        self.networks.clear()
        require(not docker('ps', '-a', '--filter', 'name=^/' + self.prefix + '-', '--format', '{{.Names}}').stdout.strip(),
                'Cleanup left containers behind')
        self.record('cleanup', 'pass')

    def run(self):
        require(self.cycles >= 2, 'At least two clean cycles required')
        require(self.manifest['inputs'] == inputs(self.profile), 'Source/harness changed since preparation; prepare again')
        require(manifest_ready(self.manifest), 'Prepared resources evicted/missing; prepare this profile again')
        require(docker('image', 'inspect', self.manifest['images']['runtime'], '--format', '{{.Id}}').stdout.strip()
                == self.manifest['runtime_image_id'], 'Runtime image reference changed')
        resources = Resources(self.containers)
        try:
            for self.cycle in range(1, self.cycles + 1):
                self.prefix = 'hms-tests-' + self.profile + '-' + uuid.uuid4().hex[:10]
                try:
                    ip = self.probe()
                    network = self.network('test-net')
                    self.safety(ip, network)
                    if self.profile == 'backend':
                        self.backend(network)
                    elif self.profile == 'frontend':
                        self.case('frontend-unit', ['node', '/tools/guard.cjs', 'component'], artifacts=('junit.xml', 'jest.json'))
                    else:
                        self.case('browser-tooling', ['node', '/tools/guard.cjs', 'browser'], timeout=180,
                                  artifacts=('junit.xml', 'browser.json'))
                        self.record('full-HMS-journeys', 'deferred', reason='Licensed full assets and journey fixtures pending 2F; tooling fixture only')
                finally:
                    self.cleanup()
                self.completed += 1
            self.report['outcome'] = 'blocked' if any(row['status'] == 'blocked' for row in self.rows) else 'pass'
        except KeyboardInterrupt:
            self.report['outcome'] = 'cancelled'
            raise
        except (RuntimeError, subprocess.TimeoutExpired) as error:
            self.report['error'] = str(error)
            raise
        finally:
            self.report['resources'] = resources.finish()
            self.report['completed_cycles'] = self.completed
            if self.profile == 'backend' and self.regression:
                try:
                    paths = [self.output / f"cycle-{row['cycle']}-{row['check']}-artifacts" / 'clover.xml'
                             for row in self.rows if row.get('tests') and row.get('artifacts_complete')]
                    self.report['coverage'] = coverage_summary(paths)
                except (OSError, ValueError, ET.ParseError, RuntimeError) as error:
                    self.report['coverage_error'] = str(error)
                    if self.report['outcome'] != 'cancelled':
                        self.report['outcome'] = 'blocked'
            if self.report['outcome'] == 'pass' and not profile_complete(self.report, self.report['action']):
                self.report['outcome'] = 'blocked'
                self.report['error'] = 'Required profile checks incomplete'
            write_json(self.output / 'report.json', self.report)
        return self.report


def required_checks(profile, action, suite='all'):
    checks = {'canary-control', 'private-isolation', 'unit-isolation', 'reject-production',
              'reject-database', 'reject-credential', 'reject-host-sentinel', 'reject-cache', 'cleanup'}
    if profile == 'backend':
        checks |= {'php-tooling', 'pest-discovery', 'pest-unit'}
        if suite != 'backend-unit' or action != 'pr-check':
            checks |= {'mysql', 'application', 'laravel-schema', 'schema', 'fixtures', 'routines'}
        if action == 'pr-check':
            if suite in {'all', 'http'}:
                checks |= {'foundation-database', 'http-authentication', 'http-booking', 'http-toolpages', 'http-toolaccess', 'http-membersearch'}
            if suite in {'all', 'sql'}:
                checks |= {'sql-statusaccess', 'sql-induction', 'sql-charging', 'sql-reporting'}
            if suite in {'all', 'backend', 'backend-unit'}:
                checks |= {'foundation-unit', 'backend-unit'}
            if suite in {'all', 'backend', 'backend-integration'}:
                checks |= {'foundation-database', 'backend-repository', 'backend-lifecycleaudit',
                           'backend-membershipretention', 'backend-reportingworkflow'}
    else:
        checks.add('frontend-unit' if profile == 'frontend' else 'browser-tooling')
    return checks


def profile_complete(report, action):
    count = report.get('requested_cycles', 0)
    if (report.get('action') != action or report.get('outcome') != 'pass' or count < 2
            or report.get('completed_cycles') != count or report.get('profile') not in PROFILES):
        return False
    rows = report.get('results', [])
    if any(row.get('status') == 'blocked' for row in rows):
        return False
    required = required_checks(report['profile'], action, report.get('suite', 'all'))
    return all(required <= {row.get('check') for row in rows if row.get('cycle') == cycle
                            and row.get('status') == 'pass'} for cycle in range(1, count + 1))


def aggregate(reports, action='pr-check'):
    expected = set(PROFILES)
    found = [r.get('profile') for r in reports]
    identity = {(digest(r.get('baseline')), r.get('tooling_digest')) for r in reports}
    passed = (set(found) == expected and len(found) == len(expected) and len(identity) == 1
              and all(r.get('suite', 'all') == 'all' and profile_complete(r, action) for r in reports))
    return {'action': action, 'outcome': 'pass' if passed else 'blocked', 'required_profiles': list(PROFILES),
            'profiles': reports, 'pending_chunks': ['2E', '2F', '2G'],
            'scope': 'Delivered foundation, SQL, backend PHP, routed HTTP and tooling checks; full HMS journeys explicitly deferred, not passed.'}


def main():
    import fcntl
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['prepare', 'smoke', 'pr-check', 'clean', 'aggregate'])
    parser.add_argument('--profile', choices=(*PROFILES, 'all'))
    parser.add_argument('--cycles', type=int, default=2)
    parser.add_argument('--suite', choices=['all', 'sql', 'backend', 'backend-unit', 'backend-integration', 'http'], default='all', help='Select SQL or PHP backend business suites; partial selections require backend pr-check and cannot satisfy full aggregation')
    parser.add_argument('--output', type=Path, default=HERE / 'artifacts')
    parser.add_argument('--retain-cache', action='store_true', help='Keep the last combined profile cache on larger hosts')
    args = parser.parse_args()
    profile = args.profile or ('backend' if args.action == 'prepare' else 'all')
    require(args.suite == 'all' or (args.action == 'pr-check' and profile == 'backend'),
            'Partial --suite selection requires pr-check --profile backend')
    CACHE.mkdir(exist_ok=True)
    with (CACHE / 'run.lock').open('w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('Another harness preparation/run is active; profiles must run serially')
        if args.action == 'clean':
            clean(profile)
            return 0
        if args.action == 'prepare':
            require(profile != 'all', 'Prepare one profile at a time; pr-check --profile all stages preparation automatically')
            resources = Resources()
            try:
                prepare(profile)
            finally:
                write_json(CACHE / (profile + '-preparation-resources.json'), resources.finish())
            return 0
        output = args.output.resolve()
        if args.action == 'aggregate':
            reports = [json.loads((output / p / 'report.json').read_text()) for p in PROFILES if (output / p / 'report.json').exists()]
            result = aggregate(reports)
            write_json(output / 'report.json', result)
            return 0 if result['outcome'] == 'pass' else 1
        if profile != 'all':
            manifest = json.loads((CACHE / (profile + '.json')).read_text())
            report = ProfileRun(manifest, args.cycles, output, args.action == 'pr-check', args.suite).run()
            return 0 if report['outcome'] == 'pass' else 1
        reports = []
        resources = Resources()
        try:
            for selected in PROFILES:
                prep_resources = Resources()
                try:
                    manifest = prepare(selected)
                finally:
                    prep_result = prep_resources.finish()
                try:
                    runner = ProfileRun(manifest, args.cycles, output / selected, args.action == 'pr-check')
                    report = runner.run()
                except (RuntimeError, subprocess.TimeoutExpired) as error:
                    report = runner.report
                    report['error'] = str(error)
                report['preparation_resources'] = prep_result
                write_json(output / selected / 'report.json', report)
                reports.append(report)
                if not args.retain_cache:
                    clean(selected)
        finally:
            result = aggregate(reports, args.action)
            result['resources'] = resources.finish()
            write_json(output / 'report.json', result)
        return 0 if result['outcome'] == 'pass' else 1


def interrupted(signum, frame):
    raise KeyboardInterrupt('Interrupted; cleaning up profile resources')


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, interrupted)
    try:
        raise SystemExit(main())
    except (RuntimeError, ValueError, OSError, subprocess.TimeoutExpired) as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except KeyboardInterrupt:
        print('Cancelled; profile resources reconciled; completed/partial reports retained.', flush=True)
        raise SystemExit(130)
