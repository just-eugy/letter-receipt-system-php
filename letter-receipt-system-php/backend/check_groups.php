<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$router = $app->make('router');
$groups = $router->getMiddlewareGroups();
print_r($groups);