<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
print_r($app->getProviders());