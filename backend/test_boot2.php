<?php
require 'vendor/autoload.php';

$start = microtime(true);
$app = require_once 'bootstrap/app.php';

// Load environment and config first
$app->make(\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class)->bootstrap($app);
$app->make(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class)->bootstrap($app);
$app->make(\Illuminate\Foundation\Bootstrap\HandleExceptions::class)->bootstrap($app);
$app->make(\Illuminate\Foundation\Bootstrap\RegisterFacades::class)->bootstrap($app);

// Register providers
$registerStart = microtime(true);
$app->make(\Illuminate\Foundation\Bootstrap\RegisterProviders::class)->bootstrap($app);
$registerTime = microtime(true) - $registerStart;
echo "RegisterProviders: " . ($registerTime * 1000) . "ms\n";

// Boot providers one by one with timing
$providers = $app->getProviders();
echo "Total providers: " . count($providers) . "\n";

foreach ($providers as $provider) {
    $providerStart = microtime(true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $providerTime = microtime(true) - $providerStart;
    if ($providerTime > 0.1) { // Only show providers taking > 100ms
        echo "Provider " . get_class($provider) . ": " . ($providerTime * 1000) . "ms\n";
    }
}

$totalTime = microtime(true) - $start;
echo "Total boot time: " . ($totalTime * 1000) . "ms\n";