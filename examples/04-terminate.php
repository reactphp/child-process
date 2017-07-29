<?php

use React\EventLoop\Factory;
use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

// start a process that takes 10s to terminate
$process = new Process('sleep 10');
$process->start($loop);

// report when process exits
$process->on('exit', function ($exit, $term) {
    var_dump($exit, $term);
});

// forcefully terminate process after 2s
$loop->addTimer(2.0, function () use ($process) {
    $process->stdin->close();
    $process->stdout->close();
    $process->stderr->close();
    $process->terminate();
});

$loop->run();
