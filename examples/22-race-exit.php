<?php

use React\EventLoop\Factory;
use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$first = new Process('php -r "sleep(2);"', null, null, array());
$first->start($loop);

$first->on('exit', function ($code) {
    echo 'First closed ' . $code . PHP_EOL;
});

$second = new Process('php -r "sleep(1);"', null, null, array());
$second->start($loop);

$second->on('exit', function ($code) {
    echo 'Second closed ' . $code . PHP_EOL;
});

$loop->run();
