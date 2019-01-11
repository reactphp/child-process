<?php

use React\EventLoop\Factory;
use React\ChildProcess\Process;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$server = new React\Socket\Server('127.0.0.1:0', $loop);
$server->on('connection', function (React\Socket\ConnectionInterface $connection) {
    $connection->on('data', function ($chunk) {
        // escape control codes (useful in case encoding or binary data is not working as expected)
        // $chunk = addcslashes($chunk,"\0..\37!@\177..\377");

        // convert default code page 850 to UTF-8 (German Windows in this case)
        $chunk = iconv('CP850','UTF-8', $chunk);

        echo $chunk;
    });
});

$command = 'php -r "echo 1;sleep(1);echo 2;sleep(1);echo 3;"';
// $command = 'ping google.com';
// $command = 'C:\Windows\System32\ping google.com';

// use stream redirections to consume output of child process in another helper process and forward to socket
$code = '$s=stream_socket_client($argv[1]);do{fwrite($s,$d=fread(STDIN, 8192));}while(isset($d[0]));';
$process = new Process(
    $command . ' | php -r ' . escapeshellarg($code) . ' ' . $server->getAddress(),
    null,
    null,
    array()
);
$process->start($loop);

$process->on('exit', function ($code) use ($server) {
    $server->close();
    echo PHP_EOL . 'Process closed ' . $code . PHP_EOL;
});

$loop->run();
