<?php

use React\ChildProcess\Process;
use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

// start a process that takes 10s to terminate
$process = new Process('php -r "sleep(10);"', null, null, array());
$process->start();

// report when process exits
$process->on('exit', function ($exit, $term) {
    var_dump($exit, $term);
});

// forcefully terminate process after 2s
Loop::addTimer(2.0, function () use ($process) {
    foreach ($process->pipes as $pipe) {
        $pipe->close();
    }
    $process->terminate();
});
