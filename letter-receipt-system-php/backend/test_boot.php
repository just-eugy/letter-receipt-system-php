<?php
require 'vendor/autoload.php';

$start = microtime(true);
$app = require_once 'bootstrap/app.php';
$bootstrapTime = microtime(true) - $start;
echo "Bootstrap time: " . ($bootstrapTime * 1000) . "ms\n";

// Manually boot with timing
$providers = [
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
    \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
    \Illuminate\Foundation\Bootstrap\BootProviders::class,
];

foreach ($providers as $provider) {
    $providerStart = microtime(true);
    $app->make($provider)->bootstrap($app);
    $providerTime = microtime(true) - $providerStart;
    echo "Provider $provider: " . ($providerTime * 1000) . "ms\n";
}

$totalTime = microtime(true) - $start;
echo "Total boot time: " . ($totalTime * 1000) . "ms\n";