# Child Process Component

[![Build Status](https://secure.travis-ci.org/reactphp/child-process.png?branch=master)](http://travis-ci.org/reactphp/child-process) [![Code Climate](https://codeclimate.com/github/reactphp/child-process/badges/gpa.svg)](https://codeclimate.com/github/reactphp/child-process)

Library for executing child processes.

This library integrates [Program Execution](http://php.net/manual/en/book.exec.php)
with the [EventLoop](https://github.com/reactphp/event-loop).
Child processes launched may be signaled and will emit an
`exit` event upon termination.
Additionally, process I/O streams (i.e. STDIN, STDOUT, STDERR) are exposed
as [Streams](https://github.com/reactphp/stream).

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Processes](#processes)
  * [EventEmitter Events](#eventemitter-events)
  * [Methods](#methods)
  * [Stream Properties](#stream-properties)
  * [Prepending Commands with `exec`](#prepending-commands-with-exec)
  * [Sigchild Compatibility](#sigchild-compatibility)
  * [Command Chaining](#command-chaining)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

```php
$loop = React\EventLoop\Factory::create();

$process = new React\ChildProcess\Process('echo foo');
$process->start($loop);

$process->stdout->on('data', function ($chunk) {
    echo $chunk;
});

$process->on('exit', function($exitCode, $termSignal) {
    echo 'Process exited with code ' . $exitCode . PHP_EOL;
});

$loop->run();
```

See also the [examples](examples).

## Processes

### EventEmitter Events

* `exit`: Emitted whenever the process is no longer running. Event listeners
  will receive the exit code and termination signal as two arguments.

### Methods

* `start()`: Launches the process and registers its IO streams with the event
  loop. The stdin stream will be left in a paused state.
* `terminate()`: Send the process a signal (SIGTERM by default).

There are additional public methods on the Process class, which may be used to
access fields otherwise available through `proc_get_status()`.

### Stream Properties

Once a process is started, its I/O streams will be constructed as instances of
`React\Stream\Stream`. Before `start()` is called, these properties are `null`.
Once a process terminates, the streams will become closed but not unset.

* `$stdin`
* `$stdout`
* `$stderr`

Each of these implement the underlying
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface)
and you can use any of its events and methods as usual:

```php
$process->stdout->on('data', function ($chunk) {
    echo $chunk;
});

$process->stdout->on('end', function () {
    echo 'ended';
});

$process->stdout->on('error', function (Exception $e) {
    echo 'error: ' . $e->getMessage();
});

$process->stdout->on('close', function () {
    echo 'closed';
});

$process->stdin->write($data);
$process->stdin->end($data = null);
$process->stdin->close();
// …
```

For more details, see the
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface).

### Prepending Commands with `exec`

Symfony pull request [#5759](https://github.com/symfony/symfony/issues/5759)
documents a caveat with the
[Program Execution](http://php.net/manual/en/book.exec.php) extension. PHP will
launch processes via `sh`, which obfuscates the underlying process' PID and
complicates signaling (our process becomes a child of `sh`). As a work-around,
prepend the command string with `exec`, which will cause the `sh` process to be
replaced by our process.

### Sigchild Compatibility

When PHP has been compiled with the `--enabled-sigchild` option, a child
process' exit code cannot be reliably determined via `proc_close()` or
`proc_get_status()`. Instead, we execute the child process with a fourth pipe
and use that to retrieve its exit code.

This behavior is used by default and only when necessary. It may be manually
disabled by calling `setEnhanceSigchildCompatibility(false)` on the Process
before it is started, in which case the `exit` event may receive `null` instead
of the actual exit code.

**Note:** This functionality was taken from Symfony's
[Process](https://github.com/symfony/process) compoment.

### Command Chaining

Command chaning with `&&` or `;`, while possible with `proc_open()`, should not
be used with this component. There is currently no way to discern when each
process in a chain ends, which would complicate working with I/O streams. As an
alternative, considering launching one process at a time and listening on its
`exit` event to conditionally start the next process in the chain. This will
give you an opportunity to configure the subsequent process' I/O streams.

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/child-process:^0.4.2
```

More details about version upgrades can be found in the [CHANGELOG](CHANGELOG.md).

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](http://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT, see [LICENSE file](LICENSE).
