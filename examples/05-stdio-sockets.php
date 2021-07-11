<?php

use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_VERSION_ID < 80000) {
    exit('Socket descriptors require PHP 8+' . PHP_EOL);
}

$process = new Process(
    'php -r ' . escapeshellarg('echo 1;sleep(1);fwrite(STDERR,2);exit(3);'),
    null,
    null,
    [
        ['socket'],
        ['socket'],
        ['socket']
    ]
);
$process->start();

$process->stdout->on('data', function ($chunk) {
    echo '(' . $chunk . ')';
});

$process->stderr->on('data', function ($chunk) {
    echo '[' . $chunk . ']';
});

$process->on('exit', function ($code) {
    echo 'EXIT with code ' . $code . PHP_EOL;
});
