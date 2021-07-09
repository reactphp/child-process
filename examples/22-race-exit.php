<?php

use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

$first = new Process('php -r "sleep(2);"', null, null, array());
$first->start();

$first->on('exit', function ($code) {
    echo 'First closed ' . $code . PHP_EOL;
});

$second = new Process('php -r "sleep(1);"', null, null, array());
$second->start();

$second->on('exit', function ($code) {
    echo 'Second closed ' . $code . PHP_EOL;
});
