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

// Get providers from the container
$providers = [];
foreach ($app->getBindings() as $abstract => $binding) {
    if (is_string($binding) && class_exists($binding) && is_subclass_of($binding, \Illuminate\Support\ServiceProvider::class)) {
        $providers[] = $binding;
    }
}

echo "Found " . count($providers) . " provider bindings\n";

// Boot providers one by one with timing
foreach ($providers as $providerClass) {
    $provider = $app->make($providerClass);
    $providerStart = microtime(true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $providerTime = microtime(true) - $providerStart;
    if ($providerTime > 0.1) { // Only show providers taking > 100ms
        echo "Provider $providerClass: " . ($providerTime * 1000) . "ms\n";
    }
}

$totalTime = microtime(true) - $start;
echo "Total boot time: " . ($totalTime * 1000) . "ms\n";