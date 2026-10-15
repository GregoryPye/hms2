<?php

require_once __DIR__ . "/../Support/Environment.php";

try {
    try {
        preflight();
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(78);
    }
    $mode = $argv[1] ?? 'preflight';
    $result = [];
    switch ($mode) {
        case 'preflight':
            break;
        case 'isolation':
            $result = isolation();
            break;
        case 'control':
            $canary = getenv('CANARY_IP');
            ensure(filter_var($canary, FILTER_VALIDATE_IP) !== false, 'Invalid canary address');
            $stream = @stream_socket_client("tcp://$canary:18080", $errno, $error, 2);
            ensure($stream !== false, 'Controlled listener is not reachable on its own network');
            fclose($stream);
            $result = ['canary' => 'reachable on control network'];
            break;
        case 'tooling':
            require '/app/vendor/autoload.php';
            foreach (['pdo_mysql', 'redis', 'pcov', 'sockets', 'mbstring', 'intl'] as $extension) {
                ensure(extension_loaded($extension), "Missing extension: $extension");
            }
            $result = ['php' => PHP_VERSION, 'pest' => Composer\InstalledVersions::getPrettyVersion('pestphp/pest'),
                'phpunit' => Composer\InstalledVersions::getPrettyVersion('phpunit/phpunit'),
                'laravel' => Composer\InstalledVersions::getPrettyVersion('laravel/framework'),
                'redis_extension' => phpversion('redis'), 'coverage_extension' => phpversion('pcov')];
            break;
        case 'mysql':
            $db = database();
            $result = $db->query('SELECT VERSION() AS version, @@sql_mode AS sql_mode, @@collation_database AS collation')->fetch(PDO::FETCH_ASSOC);
            ensure(str_starts_with($result['version'], '8.4.'), 'Unexpected MySQL version');
            $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($grants as $grant) {
                ensure(! preg_match('/GRANT (?!USAGE).* ON \*\.\*/', $grant), 'Overbroad database grants');
            }
            try {
                $db->query('SELECT User FROM mysql.user');
                throw new RuntimeException('Database account can read system users');
            } catch (PDOException $expected) {
                $result['system_database_access'] = 'denied';
            }
            break;
        case 'application':
            $app = application();
            $result = ['bootstrap' => 'pass', 'routes' => count($app['router']->getRoutes()),
                'entity_manager' => get_class($app['em']), 'queue' => get_class($app['queue']->connection())];
            break;
        case 'laravel-schema':
            $app = application();
            $code = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            echo Illuminate\Support\Facades\Artisan::output();
            ensure($code === 0, 'Laravel migrations failed');
            $result = ['schema' => 'Laravel repository migrations loaded'];
            break;
        case 'schema':
            $app = application();
            $code = Illuminate\Support\Facades\Artisan::call('doctrine:migrations:migrate', ['--no-interaction' => true]);
            echo Illuminate\Support\Facades\Artisan::output();
            ensure($code === 0, 'Doctrine migration command failed');
            $result = ['schema' => 'Doctrine repository migrations loaded'];
            break;
        case 'fixtures':
            $app = application();
            $em = $app['em'];
            $tool = (new HMS\Factories\Tools\ToolFactory)->create('SmokeTool', 'Synthetic smoke tool', false, 0, 30, 120);
            $app->make(HMS\Repositories\Tools\ToolRepository::class)->save($tool);
            $id = $tool->getId();
            $em->clear();
            $loaded = $em->find(HMS\Entities\Tools\Tool::class, $id);
            ensure($loaded->getDisplayName() === 'Synthetic smoke tool', 'Doctrine fixture readback failed');
            $row = Illuminate\Support\Facades\DB::table('tools')->where('id', $id)->first();
            ensure($row->name === 'SmokeTool', 'Laravel/Doctrine readback differs');
            $result = ['fixture' => 'Doctrine persist/clear/read and Laravel read agree'];
            break;
        case 'routines':
            $db = database();
            $counts = loadSql($db);
            $statement = $db->query('CALL sp_tool_get_status(-1)');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            ensure(count($rows) === 1 && $rows[0]['tool_name'] === 'SmokeTool', 'Stored procedure readback failed');
            $result = ['loaded_files' => $counts, 'tool_status_procedure' => 'pass'];
            break;
        default:
            throw new RuntimeException('Unknown smoke mode');
    }
    echo json_encode(['status' => 'pass', 'check' => $mode, 'evidence' => $result], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    // The process has synthetic configuration only; avoid dumping environment or stack arguments.
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
