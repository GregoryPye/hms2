#!/usr/bin/env python3
"""Stage-1 environment runner. Uses only the local Docker daemon; never deploys HMS."""
import argparse
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
import time
import urllib.request
import uuid

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
CACHE = HERE / '.cache'
DOCKER = ['docker', '--host=unix:///var/run/docker.sock']
DOCKER_ENV = {k: v for k, v in os.environ.items() if k not in {
    'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'}}
IMAGES = json.loads((HERE / 'images.json').read_text())
HARNESS_FILES = [
    'run.py', 'smoke.php', 'images.json', 'php.Dockerfile', 'playwright-seccomp.json',
    'frontend/package.json', 'frontend/package-lock.json',
    'frontend/smoke.test.cjs', 'frontend/browser.cjs',
]
PROXIES = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
           'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy']
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


def command(args, *, timeout=120, check=True, **kwargs):
    result = subprocess.run(args, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, timeout=timeout, **kwargs)
    if check and result.returncode:
        raise RuntimeError(f'Command failed ({result.returncode}): {args[:3]}\n{result.stdout[-6000:]}')
    return result


def docker(*args, **kwargs):
    return command(DOCKER + list(args), env=DOCKER_ENV, **kwargs)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def readable_tree(root):
    # Host checkout umasks must not make the non-root test user unable to read source.
    root.chmod(0o755)
    for path in root.rglob('*'):
        path.chmod(0o755 if path.is_dir() else 0o644)


def snapshot(context):
    source = context / 'source'
    source.mkdir()
    # Read only named, tracked product files; never copy the worktree wholesale.
    tracked = command(['git', 'ls-files', '-z'], cwd=ROOT).stdout.split('\0')
    allowed_roots = {'app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests', 'public'}
    allowed_files = {'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml'}
    files = []
    for name in tracked:
        p = Path(name)
        if not name or (p.parts[0] not in allowed_roots and name not in allowed_files):
            continue
        if p.parts[:2] in [('bootstrap', 'cache'), ('database', 'database')]:
            continue
        if any(x.startswith('.') or x in {'vendor', 'node_modules', 'storage', 'artifacts', '.cache'} for x in p.parts):
            continue
        if p.suffix.lower() in {'.key', '.pem', '.p12', '.pfx'} or p.name in {'auth.json', 'users.json'}:
            continue
        original = ROOT / p
        if original.is_symlink():
            raise RuntimeError(f'Refusing source symlink: {name}')
        if not original.is_file():
            continue
        target = source / p
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(original, target)
        files.append((name, hashlib.sha256(original.read_bytes()).hexdigest()))
    for name in HARNESS_FILES:
        p = HERE / name
        if p.is_symlink():
            raise RuntimeError('Refusing harness symlink')
        target = source / 'tests/Environment' / p.relative_to(HERE)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(p, target)
    for name in ['bootstrap/cache', 'storage', 'database/factories', 'database/seeds']:
        (source / name).mkdir(parents=True, exist_ok=True)
    require(not list(source.rglob('.env*')), 'Snapshot contains an environment file')
    require(not (source / 'bootstrap/cache/config.php').exists(), 'Snapshot contains cached configuration')
    # Read the gitlink; do not initialize or modify the checked-out submodule.
    tree = command(['git', 'ls-tree', 'HEAD', 'database/database'], cwd=ROOT).stdout
    revision = re.search(r'commit ([a-f0-9]{40})', tree).group(1)
    url = f'https://codeload.github.com/NottingHack/database/tar.gz/{revision}'
    with urllib.request.urlopen(url, timeout=45) as response:
        archive = response.read(16 * 1024 * 1024)
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as tar:
        for member in tar.getmembers():
            parts = Path(member.name).parts[1:]
            if not member.isfile() or not parts or '..' in parts:
                continue
            p = Path(*parts)
            if p.parts[0] not in {'procedures', 'views'} or p.suffix != '.sql':
                continue
            target = source / 'database/database' / p
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(tar.extractfile(member).read())
    readable_tree(source)
    return {'commit': command(['git', 'rev-parse', 'HEAD'], cwd=ROOT).stdout.strip(),
            'submodule': revision, 'source_hashes': dict(files),
            'harness_hashes': {name: hashlib.sha256((HERE / name).read_bytes()).hexdigest() for name in HARNESS_FILES}}


def prepare():
    CACHE.mkdir(exist_ok=True)
    docker('info', '--format', '{{.ServerVersion}}')
    ca = os.environ.get('SSL_CERT_FILE', '/etc/ssl/certs/ca-certificates.crt')
    if not Path(ca).is_file():
        raise RuntimeError('A trusted CA bundle is required for dependency preparation')
    for name in ['php', 'composer', 'mysql', 'browser']:
        print(f'Preparing pinned {name} image', flush=True)
        if docker('image', 'inspect', IMAGES[name], check=False).returncode:
            docker('pull', IMAGES[name], timeout=900)
    with tempfile.TemporaryDirectory(prefix='hms-prepare-') as temp:
        context = Path(temp)
        manifest = snapshot(context)
        shutil.copyfile(HERE / 'php.Dockerfile', context / 'php.Dockerfile')
        (context / 'frontend').mkdir()
        for name in HARNESS_FILES:
            if name.startswith('frontend/'):
                shutil.copyfile(HERE / name, context / name)
        readable_tree(context / 'frontend')
        # Inputs contain no host .npmrc or app dependencies; only public test tooling.
        if not (context / 'frontend/package-lock.json').exists():
            raise RuntimeError('Commit the test-tooling lockfile before preparation')
        for kind in ['php']:
            image = f'hms2-stage1-{kind}:local'
            buildargs = {'PHP_IMAGE': IMAGES['php'], 'COMPOSER_IMAGE': IMAGES['composer']} if kind == 'php' else {'BROWSER_IMAGE': IMAGES['browser']}
            args = ['build', '--secret', f'id=ca,src={ca}', '-f', str(context / f'{kind}.Dockerfile'), '-t', image]
            for key, value in buildargs.items():
                args += ['--build-arg', f'{key}={value}']
            print(f'Building {kind} tooling; scripts disabled during dependency installation', flush=True)
            result = docker(*args, str(context), timeout=1800, check=False)
            (CACHE / f'{kind}-build.log').write_text(result.stdout)
            if result.returncode:
                raise RuntimeError(f'{kind} build failed; inspect {CACHE / (kind + "-build.log")}\n{result.stdout[-4000:]}')
            manifest[kind + '_image'] = docker('image', 'inspect', image, '--format', '{{.Id}}').stdout.strip()
        # Keep public frontend tooling in an explicit dependency volume, not in
        # multiple copies of the large browser image on vfs-backed Docker hosts.
        fingerprint = hashlib.sha256(b'readable-v1' + b''.join(p.read_bytes() for p in sorted((context / 'frontend').glob('*')))
                                     + (context / 'source/resources/js/components/Tools/ToolGrantModal.vue').read_bytes()).hexdigest()[:16]
        volume = 'hms-stage1-tools-' + fingerprint
        exists = docker('volume', 'inspect', volume, check=False)
        if exists.returncode:
            docker('volume', 'create', '--label', 'hms2.stage1=frontend-tools', volume)
            name = 'hms-tools-prepare-' + uuid.uuid4().hex[:10]
            try:
                docker('create', '--name', name, '--network', 'none', '--mount', f'type=volume,src={volume},dst=/tools',
                       '--entrypoint', '/bin/true', IMAGES['browser'])
                docker('cp', str(context / 'frontend') + '/.', name + ':/tools/')
                docker('cp', str(context / 'source/resources/js/components/Tools/ToolGrantModal.vue'), name + ':/tools/ToolGrantModal.vue')
            finally:
                docker('rm', '-fv', name, check=False)
            try:
                print('Preparing public frontend test dependencies (scripts disabled)', flush=True)
                result = docker('run', '--name', name, '--mount', f'type=volume,src={volume},dst=/tools',
                                '--mount', f'type=bind,src={ca},dst=/run/proxy-ca.pem,readonly', '-w', '/tools',
                                '-e', 'NODE_EXTRA_CA_CERTS=/run/proxy-ca.pem', '-e', 'NPM_CONFIG_USERCONFIG=/dev/null',
                                '--entrypoint', 'npm', IMAGES['browser'], 'ci', '--ignore-scripts', '--no-audit', '--no-fund',
                                timeout=600, check=False)
                (CACHE / 'frontend-build.log').write_text(result.stdout)
                if result.returncode:
                    raise RuntimeError('Public frontend dependency preparation failed: ' + result.stdout[-2000:])
            except BaseException:
                docker('rm', '-fv', name, check=False)
                docker('volume', 'rm', volume, check=False)
                raise
            finally:
                docker('rm', '-fv', name, check=False)
        else:
            ensure_label = json.loads(exists.stdout)[0].get('Labels', {}).get('hms2.stage1')
            if ensure_label != 'frontend-tools':
                raise RuntimeError('Refusing foreign frontend dependency volume')
        manifest['frontend_image'] = IMAGES['browser']
        manifest['frontend_volume'] = volume
        manifest['base_images'] = IMAGES
        (CACHE / 'prepared.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print('Preparation complete. No application scripts ran during installation.', flush=True)


def run_smoke(cycles, output):
    prepared = json.loads((CACHE / 'prepared.json').read_text())
    for name, expected in prepared['source_hashes'].items():
        if hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != expected:
            raise RuntimeError(f'Source changed since preparation: {name}; prepare again')
    if not prepared.get('harness_hashes'):
        raise RuntimeError('Preparation manifest lacks harness checksums; prepare again')
    for name, expected in prepared['harness_hashes'].items():
        if hashlib.sha256((HERE / name).read_bytes()).hexdigest() != expected:
            raise RuntimeError(f'Harness changed since preparation: {name}; prepare again')
    if cycles < 2:
        raise RuntimeError('Stage 1 requires at least two clean startups')
    output.mkdir(parents=True, exist_ok=True)
    rows = []
    completed_cycles = 0
    outcome = 'incomplete'
    original_sentinel = os.environ.get('HMS_HOST_SENTINEL')
    os.environ['HMS_HOST_SENTINEL'] = 'must-not-enter-test-processes'
    # subprocess env was copied before this assignment; it is never passed to containers.
    def case(cycle, name, args, *, network='none', extra=None, expected=0, timeout=120, frontend=False):
        cname = f'{prefix}-{name}'
        containers.append(cname)
        image = prepared['frontend_image' if frontend else 'php_image']
        env = dict(ENV)
        if extra:
            env.update(extra)
        envargs = [f'{key}={value}' for key, value in env.items()]
        dockerargs = ['run', '--name', cname, '--network', network, '--read-only',
                      '--cap-drop=ALL', '--security-opt=no-new-privileges', '--pids-limit=256',
                      '--user', '1000:1000', '--entrypoint', '/usr/bin/env',
                      '--memory=2g', '--tmpfs', '/tmp:rw,nosuid,nodev,size=512m,mode=1777',
                      '--tmpfs', '/app/storage:rw,nosuid,nodev,size=128m,mode=1777',
                      '--tmpfs', '/app/bootstrap/cache:rw,nosuid,nodev,size=16m,mode=1777']
        for proxy in PROXIES:
            dockerargs += ['-e', proxy + '=']
        if frontend and name == 'browser':
            dockerargs += ['--security-opt', 'seccomp=' + str(HERE / 'playwright-seccomp.json')]
        if frontend:
            dockerargs += ['--mount', f'type=volume,src={prepared["frontend_volume"]},dst=/tools,readonly', '-w', '/tools']
        dockerargs += [image, '-i'] + envargs + args
        try:
            result = docker(*dockerargs, timeout=timeout, check=False)
            status = 'pass' if result.returncode == expected else 'blocked'
            text = result.stdout
        except subprocess.TimeoutExpired:
            status, text = 'blocked', 'Command timed out; container will be removed.'
        # Every launched test process is unprivileged and has no bind mounts/host resources.
        details = json.loads(docker('inspect', cname).stdout)[0]
        require(details['HostConfig']['ReadonlyRootfs'] and not details['HostConfig']['Privileged'], 'Unsafe container rootfs/privilege')
        require(details['HostConfig']['CapDrop'] == ['ALL'], 'Container capabilities were not dropped')
        require(not details['HostConfig']['Binds'] and not details['HostConfig']['PortBindings'], 'Unexpected bind or published port')
        require(details['Config']['User'] not in {'', '0', 'root'}, 'Test process is root')
        require(all(m['Type'] == 'volume' and m['Name'] == prepared['frontend_volume'] and not m['RW'] for m in details['Mounts']), 'Unexpected test mount')
        log = f'cycle-{cycle}-{name}.log'
        (output / log).write_text(text)
        rows.append({'cycle': cycle, 'check': name, 'status': status, 'log': log})
        print(f'cycle {cycle}: {name}: {status}', flush=True)
        docker('rm', '-fv', cname, check=False, timeout=30)
        return status == 'pass'
    try:
        for cycle in range(1, cycles + 1):
            prefix = 'hms-smoke-' + uuid.uuid4().hex[:10]
            containers = []
            network = prefix + '-net'
            canary_network = prefix + '-canary-net'
            network_created = False
            canary_created = False
            try:
                docker('network', 'create', '--internal', '--opt',
                       'com.docker.network.bridge.gateway_mode_ipv4=isolated', network)
                network_created = True
                config = json.loads(docker('network', 'inspect', network).stdout)[0]
                require(config['Internal'] and config['Options']['com.docker.network.bridge.gateway_mode_ipv4'] == 'isolated', 'Unsafe test network')
                docker('network', 'create', '--internal', '--opt',
                       'com.docker.network.bridge.gateway_mode_ipv4=isolated', canary_network)
                canary_created = True
                canary = prefix + '-canary'
                containers.append(canary)
                docker('run', '-d', '--name', canary, '--network', canary_network, '--read-only',
                       '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user', '1000:1000',
                       '--pids-limit=32', '--memory=64m', prepared['php_image'], '-i',
                       'PATH=' + ENV['PATH'], 'php', '-r',
                       '$s=stream_socket_server("tcp://0.0.0.0:18080"); while($c=stream_socket_accept($s,-1)){fclose($c);}')
                canary_ip = json.loads(docker('inspect', canary).stdout)[0]['NetworkSettings']['Networks'][canary_network]['IPAddress']
                require(case(cycle, 'canary-control', ['php', 'tests/Environment/smoke.php', 'control'],
                             network=canary_network, extra={'CANARY_IP': canary_ip}), 'Canary control failed; see its log')
                require(case(cycle, 'unit-isolation', ['php', 'tests/Environment/smoke.php', 'isolation'], extra={'CANARY_IP': canary_ip}), 'Unit isolation failed')
                require(case(cycle, 'reject-production', ['php', 'tests/Environment/smoke.php', 'preflight'], extra={'APP_ENV': 'production'}, expected=78), 'Production config was not rejected')
                require(case(cycle, 'reject-database', ['php', 'tests/Environment/smoke.php', 'preflight'], extra={'DB_HOST': 'production.invalid'}, expected=78), 'Unexpected database was not rejected')
                require(case(cycle, 'reject-credential', ['php', 'tests/Environment/smoke.php', 'preflight'], extra={'STRIPE_SECRET': 'synthetic-sentinel'}, expected=78), 'Credential sentinel was not rejected')
                require(case(cycle, 'reject-cache', ['php', '-r',
                             'file_put_contents("/app/bootstrap/cache/config.php", "<?php return [];\\n"); require "tests/Environment/smoke.php";'], expected=78), 'Cached config was not rejected')
                require(case(cycle, 'private-isolation', ['php', 'tests/Environment/smoke.php', 'isolation'], network=network, extra={'CANARY_IP': canary_ip}), 'Private network isolation failed')
                # No host port, host volume, reused data, or production credentials.
                dbname = prefix + '-database'
                containers.append(dbname)
                args = ['run', '-d', '--name', dbname, '--network', network, '--network-alias', 'mysql',
                        '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges',
                        '--user', '999:999', '--pids-limit=256', '--memory=2g',
                        '--tmpfs', '/var/lib/mysql:rw,nosuid,nodev,size=1g,uid=999,gid=999',
                        '--tmpfs', '/var/run/mysqld:rw,nosuid,nodev,uid=999,gid=999',
                        '--tmpfs', '/tmp:rw,nosuid,nodev,mode=1777',
                        '-e', 'MYSQL_ROOT_PASSWORD=test-root-only', '-e', 'MYSQL_DATABASE=hms_smoke',
                        '-e', 'MYSQL_USER=hms_smoke', '-e', 'MYSQL_PASSWORD=test-only']
                for proxy in PROXIES:
                    args += ['-e', proxy + '=']
                # TODO: Confirm production version/SQL modes and revisit strict-mode migration compatibility.
                # Temporarily permit legacy zero dates in this disposable DB; keep smoke.php modes aligned.
                args += [IMAGES['mysql'], '--mysql-native-password=ON', '--skip-log-bin',
                         '--sql-mode=ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION']
                docker(*args)
                for attempt in range(60):
                    ready = docker('exec', '-e', 'MYSQL_PWD=test-only', dbname, 'mysql', '--protocol=TCP',
                                   '-h127.0.0.1', '-uhms_smoke', '-Nse', 'SELECT 1', 'hms_smoke', timeout=10, check=False)
                    if ready.returncode == 0:
                        break
                    time.sleep(1)
                else:
                    raise RuntimeError('Disposable MySQL failed to become ready')
                case(cycle, 'php-tooling', ['php', 'tests/Environment/smoke.php', 'tooling'])
                case(cycle, 'pest-discovery', ['php', 'vendor/bin/pest', '--list-tests'], timeout=90)
                case(cycle, 'pest-unit', ['php', 'vendor/bin/pest', '--testsuite=Unit', '--no-coverage', '--do-not-cache-result'], timeout=90)
                if case(cycle, 'mysql', ['php', 'tests/Environment/smoke.php', 'mysql'], network=network):
                    if case(cycle, 'application', ['php', 'tests/Environment/smoke.php', 'application'], network=network):
                        laravel_ready = case(cycle, 'laravel-schema', ['php', 'tests/Environment/smoke.php', 'laravel-schema'], network=network, timeout=180)
                        doctrine_ready = case(cycle, 'schema', ['php', 'tests/Environment/smoke.php', 'schema'], network=network, timeout=180)
                        # Readback may be independently useful even if a later migration failed.
                        case(cycle, 'fixtures', ['php', 'tests/Environment/smoke.php', 'fixtures'], network=network)
                        if laravel_ready and doctrine_ready:
                            case(cycle, 'routines', ['php', 'tests/Environment/smoke.php', 'routines'], network=network, timeout=180)
                        else:
                            rows.append({'cycle': cycle, 'check': 'routines', 'status': 'blocked', 'reason': 'Full migration prerequisite failed; original SQL was not modified.'})
                    else:
                        for check in ['laravel-schema', 'schema', 'fixtures', 'routines']:
                            rows.append({'cycle': cycle, 'check': check, 'status': 'blocked', 'reason': 'Application bootstrap prerequisite failed.'})
                else:
                    for check in ['application', 'laravel-schema', 'schema', 'fixtures', 'routines']:
                        rows.append({'cycle': cycle, 'check': check, 'status': 'blocked', 'reason': 'MySQL prerequisite failed.'})
                case(cycle, 'frontend-unit', ['npm', 'test', '--', '--no-coverage'], frontend=True)
                case(cycle, 'browser', ['node', 'browser.cjs'], frontend=True)
                rows.append({'cycle': cycle, 'check': 'full-HMS-assets', 'status': 'deferred',
                             'reason': 'Private Font Awesome Pro assets not installed; no token supplied. Browser smoke uses a tooling fixture.'})
            finally:
                for name in reversed(containers):
                    docker('rm', '-fv', name, check=False, timeout=30)
                # A cancelled Docker client can leave a create request completing
                # in the daemon. Reconcile only this unique run's names before
                # removing networks, including such late-created containers.
                for attempt in range(8):
                    late = docker('ps', '-a', '--filter', f'name=^/{prefix}-', '--format', '{{.Names}}').stdout.splitlines()
                    for name in late:
                        if name.startswith(prefix + '-'):
                            docker('rm', '-fv', name, check=False, timeout=30)
                    time.sleep(0.5)
                if network_created:
                    docker('network', 'rm', network, timeout=30)
                if canary_created:
                    docker('network', 'rm', canary_network, timeout=30)
                leftovers = docker('ps', '-a', '--filter', f'name={prefix}', '--format', '{{.Names}}').stdout.strip()
                require(not leftovers, 'Cleanup left containers behind')
                rows.append({'cycle': cycle, 'check': 'cleanup', 'status': 'pass'})
            completed_cycles += 1
        outcome = 'blocked' if any(r['status'] == 'blocked' for r in rows) else 'pass'
    except KeyboardInterrupt:
        outcome = 'cancelled'
        raise
    finally:
        if original_sentinel is None:
            os.environ.pop('HMS_HOST_SENTINEL', None)
        else:
            os.environ['HMS_HOST_SENTINEL'] = original_sentinel
        report = {'outcome': outcome, 'completed_cycles': completed_cycles, 'requested_cycles': cycles,
                  'prepared': prepared, 'results': rows}
        (output / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
        print(f'Report: {output / "report.json"}', flush=True)
    return 1 if any(r['status'] == 'blocked' for r in rows) else 0


def interrupted(signum, frame):
    raise KeyboardInterrupt('Interrupted; cleaning up disposable resources')


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, interrupted)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['prepare', 'smoke'])
    parser.add_argument('--cycles', type=int, default=2)
    parser.add_argument('--output', type=Path, default=HERE / 'artifacts')
    options = parser.parse_args()
    try:
        if options.action == 'prepare':
            prepare()
        else:
            raise SystemExit(run_smoke(options.cycles, options.output.resolve()))
    except (RuntimeError, AssertionError, subprocess.TimeoutExpired) as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except KeyboardInterrupt:
        print('Cancelled; disposable resources reconciled. See the partial report if execution had started.', flush=True)
        raise SystemExit(130)
