<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$reflection = new ReflectionClass($app);
$method = $reflection->getMethod('getDefaultProviders');
$method->setAccessible(true);
$providers = $method->invoke($app);
print_r($providers);