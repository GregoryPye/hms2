<?php

require '/app/tests/Browser/bootstrap.php';
if (PHP_SAPI === 'cli') {
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-S', '0.0.0.0:8080',
        '/app/tests/Browser/server.php'], [['file','/dev/null','r'],
        ['file','/tmp/hms-browser-server.log','a'], ['file','/tmp/hms-browser-server.log','a']], $pipes, '/app');
    ensure(is_resource($process), 'Could not launch isolated PHP server');
    exit(proc_close($process));
}
ensure(PHP_SAPI === 'cli-server', 'Unsupported browser server SAPI');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/js/app.js','/js/manifest.js','/js/vendor.js','/css/app.css','/css/vue.css'], true)) {
    $file = '/assets'. $path;
    if (!is_file($file) || is_link($file)) { http_response_code(503); echo 'Test assets not prepared'; return; }
    header('Content-Type: '.(str_ends_with($path, '.js') ? 'application/javascript' : 'text/css'));
    readfile($file); return;
}
// Serve only repository-owned branding images; never permit arbitrary filesystem paths.
if (str_starts_with($path, '/images/')) {
    $file = realpath('/app/public'.$path);
    if ($file === false || !str_starts_with($file, '/app/public/images/') || !is_file($file)) { http_response_code(404); return; }
    header('Content-Type: '.match(pathinfo($file, PATHINFO_EXTENSION)) {'svg'=>'image/svg+xml','png'=>'image/png','ico'=>'image/x-icon','webmanifest'=>'application/manifest+json',default=>'application/octet-stream'});
    readfile($file); return;
}
$app = browserApplication(true);
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
recordBrowserEvents();
\Mockery::close();
