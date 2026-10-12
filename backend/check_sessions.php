<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$sessions = \DB::table('sessions')->get();
foreach($sessions as $s) {
    echo $s->id . ' - ' . $s->user_id . ' - ' . $s->last_activity . PHP_EOL;
}