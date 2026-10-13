<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = \App\Models\User::find(1);
$channel = 'private-deliveries';

$reflection = new ReflectionClass(\Illuminate\Support\Facades\Broadcast::class);
$method = $reflection->getMethod('checkAuth');
$method->setAccessible(true);

$result = $method->invoke(null, $user, $channel, ['channel_name' => $channel]);
var_dump($result);