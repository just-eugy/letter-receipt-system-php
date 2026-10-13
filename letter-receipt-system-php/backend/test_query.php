<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

\DB::listen(function($q) {
    echo $q->sql . ' [' . implode(', ', $q->bindings) . '] ' . $q->time . 'ms' . PHP_EOL;
});

$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
$request = \Illuminate\Http\Request::create('/api/user', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:AdminPasswordNow123!')]);
$response = $kernel->handle($request);
echo 'Status: ' . $response->getStatusCode() . PHP_EOL;