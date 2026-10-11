<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = \App\Models\User::first();
if ($u) {
    $token = $u->createToken('test')->plainTextToken;
    echo 'Token: ' . $token . PHP_EOL;
    echo 'User ID: ' . $u->id . PHP_EOL;
    echo 'User: ' . $u->username . PHP_EOL;
} else {
    echo 'No user found';
}