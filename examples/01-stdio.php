<?php

use React\ChildProcess\Process;
use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

if (DIRECTORY_SEPARATOR === '\\') {
    exit('Process pipes not supported on Windows' . PHP_EOL);
}

$process = new Process('cat');
$process->start();

$process->stdout->on('data', function ($chunk) {
    echo $chunk;
});

$process->on('exit', function ($code) {
    echo 'EXIT with code ' . $code . PHP_EOL;
});

// periodically send something to stream
$periodic = Loop::addPeriodicTimer(0.2, function () use ($process) {
    $process->stdin->write('hello');
});

// stop sending after a few seconds
Loop::addTimer(2.0, function () use ($periodic, $process) {
    Loop::cancelTimer($periodic);
    $process->stdin->end();
});
