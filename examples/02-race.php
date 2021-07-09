<?php

use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

if (DIRECTORY_SEPARATOR === '\\') {
    exit('Process pipes not supported on Windows' . PHP_EOL);
}

$first = new Process('sleep 2; echo welt');
$first->start();

$second = new Process('sleep 1; echo hallo');
$second->start();

$first->stdout->on('data', function ($chunk) {
    echo $chunk;
});

$second->stdout->on('data', function ($chunk)  {
    echo $chunk;
});
