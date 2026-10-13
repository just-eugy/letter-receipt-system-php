<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Ratchet\Client\connect;
use React\EventLoop\Factory;

$loop = Factory::create();
$conn = connect('ws://localhost:8083/app/ciywwcv5fxnb1urf2uqn?protocol=7', [], [], $loop);
$conn->then(
    function ($c) use ($loop) {
        echo 'Connected!' . PHP_EOL;
        $c->on('message', function ($msg) {
            echo 'Message: ' . $msg . PHP_EOL;
        });
        $c->send(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'private-deliveries']]));
        $loop->addTimer(2, function () use ($c, $loop) {
            $c->close();
            $loop->stop();
        });
    },
    function ($e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
        $loop->stop();
    }
);
$loop->run();