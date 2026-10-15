<?php

// Test-owned bootstrap. Never include application/vendor files before preflight.
function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function preflight(): void
{
    $expected = [
        'APP_ENV' => 'testing', 'DB_HOST' => 'mysql', 'DB_DATABASE' => 'hms_smoke',
        'DB_CONNECTION' => 'mysql', 'DB_USERNAME' => 'hms_smoke', 'DB_PASSWORD' => 'test-only',
        'DOCTRINE_METADATA' => 'yaml', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'hms_smoke_null', 'BROADCAST_CONNECTION' => 'log',
        'PASSWORDSTORE' => 'doctrine',
    ];
    foreach ($expected as $key => $value) {
        ensure(getenv($key) === $value, "Unsafe configuration rejected: $key");
    }
    foreach (['HMS_HOST_SENTINEL', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN',
        'GOOGLE_APPLICATION_CREDENTIALS', 'IDENTITY_ENDPOINT', 'COMPOSER_AUTH', 'GITHUB_TOKEN',
        'STRIPE_SECRET', 'DISCORD_TOKEN', 'MAILGUN_SECRET', 'VIMBADMIN_SECRET', 'SSH_AUTH_SOCK',
        'KRB_KEYTAB', 'DB_URL', 'FONTAWESOME_TOKEN', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY',
        'http_proxy', 'https_proxy', 'all_proxy'] as $key) {
        ensure(! getenv($key), "Unexpected credential or destination setting rejected: $key");
    }
    ensure(! file_exists('/app/.env'), 'Application .env must not be present');
    ensure(! file_exists('/app/bootstrap/cache/config.php'), 'Cached application config rejected');
    ensure(! file_exists('/var/run/docker.sock'), 'Docker socket exposed');
    ensure(! is_writable('/app/app/HMS/Tools/ToolManager.php'), 'Application source is writable');
    ensure(! is_dir('/root/.aws') && ! is_dir('/root/.ssh'), 'Host credential directory exposed');
}

function isolation(): array
{
    $routes = file('/proc/net/route');
    foreach (array_slice($routes, 1) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        ensure(($fields[1] ?? '') !== '00000000', 'Unexpected default network route');
    }
    $probes = [];
    // Documentation-only IPs, never production hosts. Connection probes send no application data.
    foreach (['192.0.2.1', '203.0.113.1'] as $host) {
        foreach ([443, 1883, 25] as $port) {
            $stream = @stream_socket_client("tcp://$host:$port", $errno, $error, 0.5);
            ensure($stream === false, 'Unexpected outbound TCP connection');
            $probes[] = "$host:$port blocked";
        }
    }
    ensure(gethostbyname('example.com') === 'example.com', 'Unexpected external DNS resolution');
    $canary = getenv('CANARY_IP');
    ensure(filter_var($canary, FILTER_VALIDATE_IP) !== false, 'Missing controlled isolation probe target');
    $stream = @stream_socket_client("tcp://$canary:18080", $errno, $error, 0.5);
    ensure($stream === false, 'Escaped into the separate canary network');
    $child = 'exit(getenv("HMS_HOST_SENTINEL") || @stream_socket_client("tcp://192.0.2.1:1883", $e, $s, 0.5) ? 1 : 0);';
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child), $output, $code);
    ensure($code === 0, 'Child process escaped isolation');
    return ['checks' => $probes, 'child_process' => 'blocked', 'default_route' => 'absent'];
}

function database(): PDO
{
    // Do not accept a URL/DSN or arbitrary connection settings from the caller.
    preflight();
    return new PDO('mysql:host=mysql;port=3306;dbname=hms_smoke;charset=utf8mb4',
        'hms_smoke', 'test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function application()
{
    preflight();
    require_once '/app/vendor/autoload.php';
    foreach (['storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions',
        'storage/logs', 'storage/proxies'] as $dir) {
        if (! is_dir('/app/' . $dir)) {
            mkdir('/app/' . $dir, 0777, true);
        }
    }
    $app = require '/app/bootstrap/app.php';
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app) {
        // An explicit test-only null connection; no production config file is changed.
        $app['config']->set('queue.connections.hms_smoke_null', ['driver' => 'null']);
        // TODO: Confirm production SQL modes and revisit strict-mode migration compatibility.
        // Match the temporary non-strict disposable server in run.py, including Laravel sessions.
        $app['config']->set('database.connections.mysql.modes', [
            'ONLY_FULL_GROUP_BY', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION',
        ]);
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    ensure($app->environment('testing'), 'Effective application environment is unsafe');
    foreach (['database.default' => 'mysql', 'database.connections.mysql.host' => 'mysql',
        'database.connections.mysql.database' => 'hms_smoke', 'cache.default' => 'array',
        'mail.default' => 'array', 'queue.default' => 'hms_smoke_null', 'broadcasting.default' => 'log',
        'passwordstore.driver' => 'doctrine', 'doctrine.managers.default.meta' => 'yaml'] as $key => $expected) {
        ensure(config($key) === $expected, "Effective configuration rejected: $key");
    }
    return $app;
}

function loadSql(PDO $db): array
{
    $counts = [];
    foreach (['procedures', 'views'] as $kind) {
        $files = glob('/app/database/database/' . $kind . '/*.sql');
        sort($files);
        $counts[$kind] = 0;
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            // Execute original statements, stripping only mysql-client DELIMITER commands.
            $delimiter = ';';
            $buffer = '';
            foreach (preg_split('/\R/', $sql) as $line) {
                if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
                    $delimiter = $match[1];
                    continue;
                }
                $buffer .= $line . "\n";
                if (str_ends_with(rtrim($buffer), $delimiter)) {
                    $statement = substr(rtrim($buffer), 0, -strlen($delimiter));
                    if (trim($statement)) {
                        try { $db->exec($statement); }
                        catch (PDOException $error) { throw new RuntimeException(basename($file) . ': ' . $error->getMessage()); }
                    }
                    $buffer = '';
                }
            }
            if (trim($buffer)) {
                $db->exec($buffer);
            }
            $counts[$kind]++;
        }
    }
    return $counts;
}
