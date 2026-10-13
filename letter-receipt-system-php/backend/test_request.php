<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Simulate a request with Basic auth
$request = \Illuminate\Http\Request::create('/api/user', 'GET', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:AdminPasswordNow123!'),
]);

$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
$response = $kernel->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";