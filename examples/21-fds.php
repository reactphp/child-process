<?php

use React\EventLoop\Factory;
use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

if (DIRECTORY_SEPARATOR === '\\') {
    exit('Process pipes not supported on Windows' . PHP_EOL);
}

$loop = Factory::create();

$process = new Process('exec 0>&- 2>&-;exec ls -la /proc/self/fd', null, null, array(
    1 => array('pipe', 'w')
));
$process->start($loop);

$process->stdout->on('data', function ($chunk) {
    echo $chunk;
});

$process->on('exit', function ($code) {
    echo 'EXIT with code ' . $code . PHP_EOL;
});

$loop->run();
