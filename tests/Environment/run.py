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
                 'guard.cjs', 'test_runner.py', 'browser.Dockerfile', 'flat.Dockerfile', 'frontend/package.json', 'frontend/package-lock.json',
                 'frontend/smoke.test.cjs', 'frontend/calendar.test.cjs', 'frontend/list.test.cjs',
                 'frontend/selection.test.cjs', 'frontend/forms.test.cjs', 'frontend/babel.config.cjs', 'frontend/transform.cjs',
                 'frontend/support/setup.cjs', 'frontend/support/fullcalendar.cjs',
                 'frontend/support/loading.cjs', 'frontend/support/plugin.cjs', 'browser/package.json', 'browser/package-lock.json', 'browser/browser.cjs',
                 'browser/journeys.cjs', 'browser/entry.cjs', 'browser/theme.scss',
                 'browser/webpack.config.cjs', 'browser/build.cjs']
FRONTEND_SOURCES = [
    'Tools/ToolCalendar.vue', 'Tools/BookingCalendarList.vue', 'Tools/ToolGrantModal.vue',
    'Tools/ToolUsageAddModal.vue', 'MemberSearch.vue', 'MemberSelectTwo.vue', 'SelectTwo.vue',
]
REGRESSION_FILES = [
    'tests/Browser/bootstrap.php', 'tests/Browser/server.php', 'tests/Browser/fixture.php',
    'tests/Support/Environment.php', 'tests/Support/Fixtures.php', 'tests/Support/Clock.php',
    'tests/Support/TransportGuard.php', 'tests/Support/DatabaseSandbox.php', 'tests/Support/run.php',
    'tests/regression.xml', 'tests/Foundation/Unit/SupportTest.php',
    'tests/Foundation/Integration/DatabaseSupportTest.php', 'tests/Support/SqlTestCase.php',
    'tests/Integration/Tools/Sql/StatusAccessTest.php', 'tests/Integration/Tools/Sql/InductionTest.php',
    'tests/Integration/Tools/Sql/ChargingTest.php', 'tests/Integration/Tools/Sql/ReportingTest.php',
    'tests/Support/BackendRuleTestCase.php', 'tests/Support/BackendDatabaseTestCase.php',
    'tests/Support/BackendLifecycleTestCase.php',
    'tests/Unit/Tools/ToolRulesTest.php', 'tests/Unit/Tools/BookingRulesTest.php',
    'tests/Unit/Tools/MaintainerCancellationTest.php', 'tests/Feature/Tools/MaintainerCancellationHttpTest.php',
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
        if any(x.startswith('.') or x in {'node_modules', 'storage', 'artifacts'} for x in p.parts):
            continue
        # Laravel's tracked mail overrides use a directory literally named vendor.
        # These are application views/themes, not installed dependency/private assets.
        if 'vendor' in p.parts and p.parts[:4] != ('resources','views','vendor','mail'):
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
    selected = dict(products) if profile in {'backend', 'browser'} else {}
    if profile == 'backend':
        tests = [n for n in command(['git', 'ls-files', '-z', 'tests'], cwd=ROOT).stdout.split('\0')
                 if n and not n.startswith('tests/Environment/') and Path(n).suffix in {'.php', '.xml'}]
        selected.update({name: file_hash(name) for name in tests + REGRESSION_FILES + ['tests/Environment/smoke.php']})
    elif profile == 'frontend':
        for relative in FRONTEND_SOURCES:
            name = 'resources/js/components/' + relative
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
    write_json(CACHE / 'ownership.json', state)
    clean_build_cache(profile)


def clean_build_cache(profile):
    """Release only recorded builder snapshots; retain prepared runtime images/volumes."""
    state = ownership()
    selected = lambda x: profile == 'all' or x['profile'] == profile
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


def flatten_runtime(source, target, profile):
    """Standard multi-stage rootfs copy; no daemon, storage-driver or host-file changes."""
    before = build_cache_ids()
    try:
        with tempfile.TemporaryDirectory(prefix='hms-flat-runtime-') as temp:
            copy_file(HERE / 'flat.Dockerfile',Path(temp)/'flat.Dockerfile')
            print(profile + ': packaging unchanged runtime filesystem into one final layer',flush=True)
            result = docker('build','--build-arg','SOURCE_IMAGE='+source,'-f',str(Path(temp)/'flat.Dockerfile'),
                            '-t',target,temp,timeout=300,check=False)
            (CACHE / (profile+'-flat-build.log')).write_text(result.stdout)
            require(result.returncode == 0,'Single-layer runtime packaging failed: '+result.stdout[-2500:])
        image_id = docker('image','inspect',target,'--format','{{.Id}}').stdout.strip()
        own('images',{'profile':profile,'ref':target,'id':image_id})
        state = ownership()
        for item in list(state['images']):
            if item['profile'] == profile and item['ref'] == source:
                require(docker('image','inspect',source,'--format','{{.Id}}').stdout.strip() == item['id'],
                        'Prepared runtime reference changed; refuse build-layer release')
                docker('image','rm',source)
                state['images'].remove(item)
        write_json(CACHE / 'ownership.json',state)
    finally:
        for cache_id in build_cache_ids()-before:
            own('build_cache',{'profile':profile,'id':cache_id})
    clean_build_cache(profile)
    return target


def backend_runtime(ca):
    fingerprint = digest({'recipe': file_hash('tests/Environment/php.Dockerfile'),
                          'php': IMAGES['php'], 'composer': IMAGES['composer']})[:16]
    flat = 'hms2-tests-php-flat:' + digest({'source':fingerprint,'packaging':file_hash('tests/Environment/flat.Dockerfile')})[:16]
    if docker('image','inspect',flat,check=False).returncode == 0:
        return flat
    tag = 'hms2-tests-php:' + fingerprint
    if docker('image', 'inspect', tag, check=False).returncode == 0:
        return flatten_runtime(tag,flat,'backend')
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
    return flatten_runtime(tag,flat,'backend')


def release_backend_build_inputs():
    """Only exact owned build inputs; final PHP runtime/source/MySQL remain prepared."""
    state = ownership()
    candidates = {IMAGES[key] for key in ('php','composer')}
    candidates |= {ref.split('@')[0] for ref in candidates}
    for item in list(state['images']):
        if item['profile'] != 'backend' or item['ref'] not in candidates:
            continue
        result = docker('image','inspect',item['ref'],check=False)
        if result.returncode == 0:
            require(json.loads(result.stdout)[0]['Id'] == item['id'], 'Build input reference changed; refuse cleanup')
            if docker('image','rm',item['ref'],check=False).returncode:
                continue  # Referenced input remains owned; never force or prune unrelated resources.
        state['images'].remove(item)
    write_json(CACHE / 'ownership.json',state)
    clean_build_cache('backend')


def browser_runtime(ca):
    version = json.loads((HERE / 'browser/package.json').read_text())['devDependencies']['@playwright/test']
    require(version == '1.56.0', 'Update Chromium installer recipe with the pinned Playwright version')
    fingerprint = digest({'recipe':file_hash('tests/Environment/browser.Dockerfile'),
                          'node':IMAGES['node'],'playwright':version})[:16]
    flat = 'hms2-tests-chromium-flat:' + digest({'source':fingerprint,'packaging':file_hash('tests/Environment/flat.Dockerfile')})[:16]
    if docker('image','inspect',flat,check=False).returncode == 0:
        return flat
    tag = 'hms2-tests-chromium:' + fingerprint
    if docker('image','inspect',tag,check=False).returncode == 0:
        return flatten_runtime(tag,flat,'browser')
    node = ensure_image('node','browser')
    before = build_cache_ids()
    try:
        with tempfile.TemporaryDirectory(prefix='hms-browser-runtime-') as temp:
            copy_file(HERE / 'browser.Dockerfile', Path(temp) / 'browser.Dockerfile')
            print('browser: building pinned Chromium-only runtime with official Playwright installer',flush=True)
            result = docker('build','--secret','id=ca,src='+str(ca),'--build-arg','NODE_IMAGE='+node,
                            '-f',str(Path(temp)/'browser.Dockerfile'),'-t',tag,temp,timeout=1200,check=False)
            (CACHE / 'browser-runtime-build.log').write_text(result.stdout)
            require(result.returncode == 0, 'Chromium-only runtime build failed: ' + result.stdout[-4000:])
        image_id = docker('image','inspect',tag,'--format','{{.Id}}').stdout.strip()
        own('images',{'profile':'browser','ref':tag,'id':image_id})
    finally:
        for cache_id in build_cache_ids()-before:
            own('build_cache',{'profile':'browser','id':cache_id})
    return flatten_runtime(tag,flat,'browser')


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
            for name in HARNESS_FILES:
                if name.startswith('frontend/') and name not in {'frontend/package.json', 'frontend/package-lock.json'}:
                    copy_file(HERE / name, dest / name.removeprefix('frontend/'))
            for relative in FRONTEND_SOURCES:
                copy_file(ROOT / 'resources/js/components' / relative, dest / 'source' / relative)
        else:
            for name in HARNESS_FILES:
                if name.startswith('browser/') and name not in {'browser/package.json', 'browser/package-lock.json'}:
                    copy_file(HERE / name, dest / name.removeprefix('browser/'))
            for relative in FRONTEND_SOURCES + ['Flash.vue']:
                copy_file(ROOT / 'resources/js/components' / relative, dest / 'source' / relative)
            for relative in ['_variables.scss', '_color-helpers.scss', 'layout/_no-more-tables.scss', 'layout/_member-search.scss']:
                copy_file(ROOT / 'resources/sass' / relative, dest / 'style' / relative)
    readable_tree(dest)


def manifest_ready(manifest):
    expected = 'hms-tests-' + manifest['profile'] + '-' + digest(manifest['inputs'])[:16]
    if manifest['volume'] != expected:
        return False
    volume = docker('volume', 'inspect', manifest['volume'], check=False)
    if volume.returncode or (json.loads(volume.stdout)[0].get('Labels') or {}).get('hms2.harness.profile') != manifest['profile']:
        return False
    if manifest['profile'] == 'browser':
        application = manifest.get('application')
        if (not application or application['inputs'] != inputs('backend') or not manifest_ready(application)):
            return False
    return (all(docker('image', 'inspect', ref, check=False).returncode == 0 for ref in manifest['images'].values())
            and docker('volume', 'inspect', manifest['volume'], check=False).returncode == 0)


def prepare(profile, retain=()):
    CACHE.mkdir(parents=True, exist_ok=True)
    docker('info', '--format', '{{.Driver}}')
    application = None
    if profile == 'browser':
        # Integrated browser execution needs the actual isolated PHP/MySQL bundle.
        # Reuse an exact matching complete browser preparation before evicting anything.
        previous_path = CACHE / 'browser.json'
        if previous_path.exists():
            previous = json.loads(previous_path.read_text())
            if previous['inputs'] == inputs(profile) and manifest_ready(previous):
                print('browser: reusing matching prepared profile', flush=True)
                return previous
        application = prepare('backend', retain=('browser',))
        # Browser and application now coexist. Drop owned build-only snapshots first.
        clean_build_cache('backend')
        release_backend_build_inputs()
    # Browser retains its explicit backend prerequisite; other profiles stay independent.
    for other in PROFILES:
        if other != profile and other not in retain and not (profile == 'browser' and other == 'backend'):
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
            # A cold cumulative run needs room for MySQL's vfs copy too. Register
            # imported build-cache records before releasing exact owned inputs;
            # the packaged runtime and borrowed images remain protected.
            for cache_id in build_cache_ids() - cache_before:
                own('build_cache', {'profile': profile, 'id': cache_id})
            release_backend_build_inputs()
            images['mysql'] = ensure_image('mysql', profile)
        else:
            images['runtime'] = ensure_image('node', profile) if profile == 'frontend' else browser_runtime(ca)
            if profile == 'browser':
                images['builder'] = ensure_image('node', profile)
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
                         '--entrypoint', 'npm', images.get('builder', images['runtime']), 'ci', '--ignore-scripts', '--no-audit', '--no-fund']
            print(profile + ': installing locked public dependencies; scripts/plugins disabled', flush=True)
            result = docker(*args, timeout=900, check=False)
            (CACHE / (profile + '-install.log')).write_text(result.stdout)
            require(result.returncode == 0, profile + ' dependency preparation failed: ' + result.stdout[-2000:])
            if profile == 'browser':
                docker('rm', '-fv', install)
                print('browser: building selected actual components with public libraries; no network', flush=True)
                result = docker('run', '--pull=never', '--name', install, '--network', 'none', '--read-only',
                    '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user', '1000:1000',
                    '--memory=2g', '--pids-limit=256', '--tmpfs', '/tmp:rw,nosuid,nodev,size=512m,mode=1777',
                    '--mount', f'type=volume,src={volume},dst=/tools', '-w', '/tools',
                    '--entrypoint', '/usr/bin/env', images['builder'], '-i', 'PATH=' + ENV['PATH'],
                    'HOME=/tmp', 'node', '/tools/build.cjs', timeout=240, check=False)
                (CACHE / 'browser-assets-build.log').write_text(result.stdout)
                require(result.returncode == 0, 'Public-only asset build failed: ' + result.stdout[-4000:])
        except BaseException:
            docker('rm', '-fv', helper, install, check=False)
            clean(profile)
            raise
        finally:
            docker('rm', '-fv', helper, install, check=False)
        manifest = {'profile': profile, 'inputs': spec, 'images': images, 'volume': volume,
                    'runtime_image_id': docker('image', 'inspect', images['runtime'], '--format', '{{.Id}}').stdout.strip()}
        if application is not None:
            manifest['application'] = application
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
            require(filename in {'junit.xml', 'clover.xml', 'jest.json', 'browser.json', 'coverage-final.json', 'coverage-summary.json', 'fixture.json', 'state.json', 'journeys.json'} and filename not in found,
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


def javascript_coverage_summary(path):
    """Validate fixed seven-SFC Istanbul scope; never substitute PHP/SQL coverage."""
    require(path.is_file() and not path.is_symlink() and path.stat().st_size <= 16 * 1024 * 1024,
            'Missing/unsafe JavaScript coverage artifact')
    data = json.loads(path.read_text())
    expected = {'/tools/source/' + name for name in FRONTEND_SOURCES}
    require(set(data) == expected | {'total'}, 'JavaScript coverage source scope incomplete/unexpected')
    metrics = ('statements', 'branches', 'functions', 'lines')
    for name, entry in data.items():
        for metric in metrics:
            counts = entry[metric]
            require(isinstance(counts['total'], int) and isinstance(counts['covered'], int)
                    and 0 <= counts['covered'] <= counts['total'], 'Invalid JavaScript coverage counts')
    for metric in metrics:
        require(data['total'][metric]['total'] > 0, 'Empty JavaScript coverage')
        for count in ('total', 'covered'):
            require(data['total'][metric][count] == sum(data[name][metric][count] for name in expected),
                    'JavaScript coverage totals inconsistent')
    return {'basis': 'Istanbul compiled Vue SFC statements/branches/functions/lines; includes failing tests; excludes CSS and external libraries',
            'source_files': len(expected), 'total': data['total'],
            'files': {name.removeprefix('/tools/source/'): data[name] for name in sorted(expected)}}


def browser_journey_summary(directory, scenario, viewport):
    counts = junit_summary(directory / 'junit.xml')
    counts.pop('assertions', None)
    require(counts['tests'] == 1, 'Unexpected browser journey case count')
    report = json.loads((directory / 'journeys.json').read_text())
    require(report.get('scenario') == scenario and report.get('viewport') == viewport,
            'Browser journey identity mismatch')
    require(report.get('status') == ('passed' if counts['passed'] == 1 else 'failed'),
            'Browser JSON/JUnit outcome mismatch')
    return counts


def browser_state_summary(path, scenario, fixture):
    state = json.loads(path.read_text())
    require(state.get('verified') is True and state.get('scenario') == scenario,
            'Unverified/mismatched application state')
    require(state.get('bookings') == 0 and state.get('transactions') == 0 and state.get('usages') == 0
            and state.get('balances') == [0,0,0,0], 'Unexpected persisted booking/financial state')
    require(state.get('events') == (['NewBooking','BookingChanged','BookingChanged','BookingCancelled']
            if scenario == 'booking' else ['NewBooking','NewBooking','BookingCancelled','BookingCancelled']
            if scenario == 'maintainer' else []), 'Unexpected persisted booking event sequence')
    expected_mail = [{'recipient_id':fixture['reader'], 'tool': 'browser'}] if scenario == 'maintainer' else []
    require(state.get('mail', []) == expected_mail, 'Unexpected/missing cancellation notification intent')
    if scenario == 'grant':
        require(state.get('target_maintainer') == 1 and state.get('audit') == 1, 'Missing persisted grant/audit')
        audit = state.get('audit_rows', [])
        require(len(audit) == 1 and audit[0]['user_id'] == fixture['target']
                and audit[0]['update_by_user_id'] == fixture['admin'] and audit[0]['removed_role_id'] is None
                and audit[0]['added_role_id'] == state.get('maintainer_role'), 'Persisted grant audit identity mismatch')
    else:
        require(state.get('target_maintainer') == 0 and state.get('audit') == 0 and state.get('audit_rows') == [],
                'Unexpected persisted access/audit state')
    return state


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
                       'scope': 'First-draft foundation, SQL, backend PHP, routed HTTP, frontend components and selected browser journeys; open contract failures and deferred transports remain visible.'}

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
        if getattr(self, 'server_assets', None):
            args += ['--mount', f'type=volume,src={self.server_assets},dst=/assets,volume-subpath=assets,readonly']
            if network != 'none':
                args += ['--network-alias', 'application']
        if self.profile == 'backend':
            args += ['--tmpfs', '/app/storage:rw,nosuid,nodev,size=128m,mode=1777',
                     '--tmpfs', '/app/bootstrap/cache:rw,nosuid,nodev,size=16m,mode=1777']
        if self.profile == 'browser':
            args += ['--security-opt', 'seccomp=' + str(HERE / 'playwright-seccomp.json')]
        for proxy in PROXIES:
            args += ['-e', proxy + '=']
        args += [self.manifest['images']['runtime'], '-i', 'PATH=' + ENV['PATH'], 'tail', '-f', '/dev/null']
        docker(*args)
        mounts = [(self.manifest['volume'], point)]
        if getattr(self, 'server_assets', None):
            mounts.append((self.server_assets, '/assets'))
        self.inspect_worker(name, network, mounts)
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

    def frontend_components(self):
        self.case('frontend-components', ['node', '/tools/guard.cjs', 'components'], timeout=150,
                  artifacts=('junit.xml', 'jest.json', 'coverage-final.json', 'coverage-summary.json'))
        row = self.rows[-1]
        try:
            require(row['artifacts_complete'], 'Required component reports missing')
            directory = self.output / f'cycle-{self.cycle}-frontend-components-artifacts'
            row['tests'] = junit_summary(directory / 'junit.xml')
            # Jest exposes passing expectations, not a reliable total of executed expectations.
            row['tests'].pop('assertions', None)
            row['coverage'] = javascript_coverage_summary(directory / 'coverage-summary.json')
            report = json.loads((directory / 'jest.json').read_text())
            require(report['numTotalTests'] == row['tests']['tests'], 'Jest/JUnit case counts disagree')
            require(report['numRuntimeErrorTestSuites'] == 0, 'Jest suite failed to load')
            row['passing_expectations'] = sum(t['numPassingAsserts'] for suite in report['testResults'] for t in suite['assertionResults'])
            row['order'] = 'seed-42 shuffle' if self.cycle % 2 == 0 else 'declaration'
            if row['tests']['passed'] != row['tests']['tests']:
                row['status'] = 'blocked'
        except (OSError, ValueError, KeyError, TypeError, ET.ParseError, RuntimeError) as error:
            row['status'], row['report_error'] = 'blocked', str(error)

    def backend(self, network):
        guard = ['php', 'tests/Environment/smoke.php']
        self.case('php-tooling', guard + ['tooling'])
        self.case('pest-discovery', ['php', 'vendor/bin/pest', '--list-tests'])
        self.case('pest-unit', ['php', 'vendor/bin/pest', 'tests/Unit/ExampleTest.php', '--no-coverage', '--do-not-cache-result'])
        if self.regression and self.suite in {'all', 'backend', 'backend-unit'}:
            self.php_suite('foundation-unit', 'FoundationUnit', 'none')
            self.php_suite('backend-unit', 'BackendUnit', 'none')
            self.php_suite('maintainer-cancel-unit', 'MaintainerCancellationUnit', 'none')
        if self.regression and self.suite == 'maintainer-cancel':
            self.php_suite('foundation-unit', 'FoundationUnit', 'none')
            self.php_suite('maintainer-cancel-unit', 'MaintainerCancellationUnit', 'none')
        self.remove(self.workers['none'])
        if self.regression and self.suite == 'backend-unit':
            return
        self.database_services(network)
        if self.regression:
            if self.suite in {'all', 'backend', 'backend-integration'}:
                self.php_suite('foundation-database', 'FoundationDatabase', network)
                for group in ['Repository', 'LifecycleAudit', 'MembershipRetention', 'ReportingWorkflow']:
                    self.php_suite('backend-' + group.lower(), 'Backend' + group, network)
            if self.suite in {'all', 'http'}:
                if self.suite == 'http':
                    self.php_suite('foundation-database', 'FoundationDatabase', network)
                for group in ['Authentication', 'Booking', 'ToolPages', 'ToolAccess', 'MemberSearch', 'MaintainerCancellation']:
                    self.php_suite('http-' + group.lower(), 'Http' + group, network)
            if self.suite == 'maintainer-cancel':
                self.php_suite('foundation-database', 'FoundationDatabase', network)
                self.php_suite('http-maintainercancellation', 'HttpMaintainerCancellation', network)
            if self.suite in {'all', 'sql'}:
                for group in ['StatusAccess', 'Induction', 'Charging', 'Reporting']:
                    self.php_suite('sql-' + group.lower(), 'Sql' + group, network)

    def database_services(self, network):
        guard = ['php', 'tests/Environment/smoke.php']
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

    def browser_journeys(self, ip, network):
        app = BrowserApplicationRun(self.manifest['application'], self.cycles, self.output, False)
        app.cycle, app.prefix = self.cycle, self.prefix + '-app'
        app.rows, app.containers = self.rows, self.containers
        app.server_assets = self.manifest['volume']
        app.safety(ip, network)
        app.remove(app.workers['none'])
        app.database_services(network)
        require(app.case('journey-seed', ['php', 'tests/Browser/fixture.php', 'seed'], network=network,
                         timeout=180, artifacts=('fixture.json',)), 'Synthetic browser fixture failed')
        directory = self.output / f'cycle-{self.cycle}-application-journey-seed-artifacts'
        fixture = json.loads((directory / 'fixture.json').read_text())
        worker = app.worker(network)
        require(docker('exec', '-d', '--user', '1000:1000', worker, '/usr/bin/env', '-i',
                       *app.runtime_env(), 'php', 'tests/Browser/server.php', check=False).returncode == 0,
                'Could not start isolated application server')
        probe = ('for($i=0;$i<30;$i++){ $s=@file_get_contents("http://127.0.0.1:8080/login"); '
                 'if($s!==false && str_contains($s,"loginForm"))exit(0);usleep(200000);} exit(1);')
        require(app.case('server-ready', ['php', '-r', probe], network=network, timeout=30), 'Application server not ready')
        require(app.case('reject-unverified-state', ['php','tests/Browser/fixture.php','verify','booking'],
                         network=network, expected=1), 'Failed persisted-state contract returned success')
        scenarios = ['visibility', 'booking', 'grant', 'denial', 'maintainer']
        viewports = ['desktop', 'mobile']
        if self.cycle % 2 == 0:
            scenarios.reverse(); viewports.reverse()
        for scenario in scenarios:
            for viewport in viewports:
                name = 'journey-' + scenario + '-' + viewport
                require(app.case(name + '-reset', ['php','tests/Browser/fixture.php','reset'], network=network, artifacts=('fixture.json',)),
                        'Browser fixture reset failed')
                reset_directory = self.output / f'cycle-{self.cycle}-application-{name}-reset-artifacts'
                reset = json.loads((reset_directory / 'fixture.json').read_text())
                require(reset == {'mode':'reset','verified':True}, 'Browser reset not verified')
                self.case(name, ['node','/tools/guard.cjs','journey'], network=network, timeout=180,
                          extra={'HMS_BROWSER_SCENARIO':scenario,'HMS_BROWSER_VIEWPORT':viewport,
                                 'HMS_BROWSER_FIXTURE':json.dumps(fixture)},
                          artifacts=('junit.xml','journeys.json'))
                row = self.rows[-1]
                try:
                    require(row['artifacts_complete'], 'Required journey reports missing')
                    row['tests'] = browser_journey_summary(
                        self.output / f'cycle-{self.cycle}-{name}-artifacts', scenario, viewport)
                    if row['tests']['passed'] != 1:
                        row['status'] = 'blocked'
                except (OSError, ValueError, ET.ParseError, RuntimeError) as error:
                    row['status'], row['report_error'] = 'blocked', str(error)
                app.case(name + '-state', ['php','tests/Browser/fixture.php','verify',scenario],
                         network=network, artifacts=('state.json',))
                state_row = self.rows[-1]
                try:
                    require(state_row['artifacts_complete'], 'Verified state artifact missing')
                    state = browser_state_summary(
                        self.output / f'cycle-{self.cycle}-application-{name}-state-artifacts' / 'state.json', scenario, fixture)
                    state_row['state'] = state
                except (OSError, ValueError, KeyError, TypeError, AttributeError, RuntimeError) as error:
                    state_row['status'], state_row['report_error'] = 'blocked', str(error)

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
        if self.profile == 'browser':
            application = self.manifest['application']
            require(docker('image','inspect',application['images']['runtime'],'--format','{{.Id}}').stdout.strip()
                    == application['runtime_image_id'], 'Application runtime image reference changed')
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
                        if self.regression:
                            self.frontend_components()
                    else:
                        self.case('browser-tooling', ['node', '/tools/guard.cjs', 'browser'], timeout=180,
                                  artifacts=('junit.xml', 'browser.json'))
                        self.remove(self.workers['none'])
                        if self.regression:
                            self.browser_journeys(ip, network)
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


class BrowserApplicationRun(ProfileRun):
    """The browser's explicit PHP/MySQL prerequisite, sharing controller cleanup ownership."""
    def case(self, name, *args, **kwargs):
        return super().case('application-' + name, *args, **kwargs)


def required_checks(profile, action, suite='all'):
    checks = {'canary-control', 'private-isolation', 'unit-isolation', 'reject-production',
              'reject-database', 'reject-credential', 'reject-host-sentinel', 'reject-cache', 'cleanup'}
    if profile == 'backend':
        checks |= {'php-tooling', 'pest-discovery', 'pest-unit'}
        if suite != 'backend-unit' or action != 'pr-check':
            checks |= {'mysql', 'application', 'laravel-schema', 'schema', 'fixtures', 'routines'}
        if action == 'pr-check':
            if suite in {'all', 'http'}:
                checks |= {'foundation-database', 'http-authentication', 'http-booking', 'http-toolpages', 'http-toolaccess', 'http-membersearch', 'http-maintainercancellation'}
            if suite == 'maintainer-cancel':
                checks |= {'foundation-unit', 'maintainer-cancel-unit', 'foundation-database', 'http-maintainercancellation'}
            if suite in {'all', 'sql'}:
                checks |= {'sql-statusaccess', 'sql-induction', 'sql-charging', 'sql-reporting'}
            if suite in {'all', 'backend', 'backend-unit'}:
                checks |= {'foundation-unit', 'backend-unit', 'maintainer-cancel-unit'}
            if suite in {'all', 'backend', 'backend-integration'}:
                checks |= {'foundation-database', 'backend-repository', 'backend-lifecycleaudit',
                           'backend-membershipretention', 'backend-reportingworkflow'}
    else:
        checks.add('frontend-unit' if profile == 'frontend' else 'browser-tooling')
        if profile == 'frontend' and action == 'pr-check':
            checks.add('frontend-components')
        if profile == 'browser' and action == 'pr-check':
            checks |= {'application-' + name for name in ['private-isolation','unit-isolation','reject-production',
                'reject-database','reject-credential','reject-host-sentinel','reject-cache',
                'mysql','application','laravel-schema','schema','fixtures','routines','journey-seed','server-ready','reject-unverified-state']}
            for scenario in ['visibility','booking','grant','denial','maintainer']:
                for viewport in ['desktop','mobile']:
                    name = 'journey-' + scenario + '-' + viewport
                    checks |= {name, 'application-' + name + '-reset', 'application-' + name + '-state'}
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
            'profiles': reports,
            'deferred_scope': ['MQTT notifier execution (HOOK-01)', 'Live websocket delivery (HOOK-02)'],
            'scope': 'Delivered foundation, SQL, backend PHP, routed HTTP, frontend components and tooling checks; selected actual HMS journeys delivered; full production assets and live broadcasts remain outside scope.'}


def main():
    import fcntl
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['prepare', 'smoke', 'pr-check', 'clean', 'aggregate'])
    parser.add_argument('--profile', choices=(*PROFILES, 'all'))
    parser.add_argument('--cycles', type=int, default=2)
    parser.add_argument('--suite', choices=['all', 'sql', 'backend', 'backend-unit', 'backend-integration', 'http', 'maintainer-cancel'], default='all', help='Select SQL or PHP backend business suites; partial selections require backend pr-check and cannot satisfy full aggregation')
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
                    if selected == 'browser':
                        clean('backend')
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
