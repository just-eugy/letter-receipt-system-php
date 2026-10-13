<?php
require 'vendor/autoload.php';

$start = microtime(true);
$app = require_once 'bootstrap/app.php';
$bootstrapTime = microtime(true) - $start;
echo "Bootstrap time: " . ($bootstrapTime * 1000) . "ms\n";

$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$bootTime = microtime(true) - $start;
echo "Boot time: " . ($bootTime * 1000) . "ms\n";

$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
$request = \Illuminate\Http\Request::create('/api/user', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:AdminPasswordNow123!')]);
$response = $kernel->handle($request);
$totalTime = microtime(true) - $start;
echo "Total time: " . ($totalTime * 1000) . "ms\n";
echo "Status: " . $response->getStatusCode() . "\n";