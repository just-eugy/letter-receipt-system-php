<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
try {
    \DB::connection()->getPdo();
    echo "DB connection OK\n";
    $tables = \DB::select('SHOW TABLES');
    echo "Tables: " . count($tables) . "\n";
} catch (\Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}