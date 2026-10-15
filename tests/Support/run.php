<?php

// Preflight precedes even the test runner's vendor autoload; never invoke this suite unguarded.
require_once __DIR__.'/Environment.php';
try {
    preflight();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(78);
}
// Docker unmounts tmpfs when a container exits. Stream only named reports before shutdown.
register_shutdown_function(static function () {
    foreach (['junit.xml', 'clover.xml'] as $name) {
        $path = '/tmp/hms-regression/'.$name;
        if (is_file($path) && ! is_link($path) && filesize($path) <= 16 * 1024 * 1024) {
            echo "\nHMS_ARTIFACT:".$name.':'.base64_encode(file_get_contents($path))."\n";
        }
    }
});
require '/app/vendor/bin/phpunit';
