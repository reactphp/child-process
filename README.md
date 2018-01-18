# Child Process

[![Build Status](https://travis-ci.org/reactphp/child-process.svg?branch=master)](https://travis-ci.org/reactphp/child-process)

Event-driven library for executing child processes with
[ReactPHP](https://reactphp.org/).

This library integrates [Program Execution](http://php.net/manual/en/book.exec.php)
with the [EventLoop](https://github.com/reactphp/event-loop).
Child processes launched may be signaled and will emit an
`exit` event upon termination.
Additionally, process I/O streams (i.e. STDIN, STDOUT, STDERR) are exposed
as [Streams](https://github.com/reactphp/stream).

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Process](#process)
  * [Stream Properties](#stream-properties)
  * [Command](#command)
  * [Termination](#termination)
  * [Sigchild Compatibility](#sigchild-compatibility)
  * [Windows Compatibility](#windows-compatibility)
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

## Process

### Stream Properties

Once a process is started, its I/O streams will be constructed as instances of
`React\Stream\ReadableStreamInterface` and `React\Stream\WritableStreamInterface`. 
Before `start()` is called, these properties are `null`.Once a process terminates, 
the streams will become closed but not unset.

* `$stdin`
* `$stdout`
* `$stderr`

Each of these implement the underlying
[`ReadableStreamInterface`](https://github.com/reactphp/stream#readablestreaminterface) or 
[`WritableStreamInterface`](https://github.com/reactphp/stream#writablestreaminterface) and 
you can use any of their events and methods as usual:

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
// …
```

For more details, see the
[`ReadableStreamInterface`](https://github.com/reactphp/stream#readablestreaminterface) and 
[`WritableStreamInterface`](https://github.com/reactphp/stream#writablestreaminterface).

### Command

The `Process` class allows you to pass any kind of command line string:

```php
$process = new Process('echo test');
$process->start($loop);
```

By default, PHP will launch processes by wrapping the given command line string
in a `sh` command, so that the above example will actually execute
`sh -c echo test` under the hood.

This is a very useful feature because it does not only allow you to pass single
commands, but actually allows you to pass any kind of shell command line and
launch multiple sub-commands using command chains (with `&&`, `||`, `;` and
others) and allows you to redirect STDIO streams (with `2>&1` and family).
This can be used to pass complete command lines and receive the resulting STDIO
streams from the wrapping shell command like this:

```php
$process = new Process('echo run && demo || echo failed');
$process->start($loop);
```

In other words, the underlying shell is responsible for managing this command
line and launching the individual sub-commands and connecting their STDIO
streams as appropriate.
This implies that the `Process` class will only receive the resulting STDIO
streams from the wrapping shell, which will thus contain the complete
input/output with no way to discern the input/output of single sub-commands.

If you want to discern the output of single sub-commands, you may want to
implement some higher-level protocol logic, such as printing an explicit
boundary between each sub-command like this:

```php
$process = new Process('cat first && echo --- && cat second');
$process->start($loop);
```

As an alternative, considering launching one process at a time and listening on
its `exit` event to conditionally start the next process in the chain.
This will give you an opportunity to configure the subsequent process I/O streams:

```php
$first = new Process('cat first');
$first->start($loop);

$first->on('exit', function () use ($loop) {
    $second = new Process('cat second');
    $second->start($loop);
});
```

Keep in mind that PHP uses the shell wrapper for ALL command lines.
While this may seem reasonable for more complex command lines, this actually
also applies to running the most simple single command:

```php
$process = new Process('yes');
$process->start($loop);
```

This will actually spawn a command hierarchy similar to this:

```
5480 … \_ php example.php
5481 …    \_ sh -c yes
5482 …        \_ yes
```

This means that trying to get the underlying process PID or sending signals
will actually target the wrapping shell, which may not be the desired result
in many cases.

If you do not want this wrapping shell process to show up, you can simply
prepend the command string with `exec`, which will cause the wrapping shell
process to be replaced by our process:

```php
$process = new Process('exec yes');
$process->start($loop);
```

This will show a resulting command hierarchy similar to this:

```
5480 … \_ php example.php
5481 …    \_ yes
```

This means that trying to get the underlying process PID and sending signals
will now target the actual command as expected.

Note that in this case, the command line will not be run in a wrapping shell.
This implies that when using `exec`, there's no way to pass command lines such
as those containing command chains or redirected STDIO streams.

As a rule of thumb, most commands will likely run just fine with the wrapping
shell.
If you pass a complete command line (or are unsure), you SHOULD most likely keep
the wrapping shell.
If you want to pass an invidual command only, you MAY want to consider
prepending the command string with `exec` to avoid the wrapping shell.

### Termination

The `exit` event will be emitted whenever the process is no longer running.
Event listeners will receive the exit code and termination signal as two
arguments:

```php
$process = new Process('sleep 10');
$process->start($loop);

$process->on('exit', function ($code, $term) {
    if ($term === null) {
        echo 'exit with code ' . $code . PHP_EOL;
    } else {
        echo 'terminated with signal ' . $term . PHP_EOL;
    }
});
```

Note that `$code` is `null` if the process has terminated, but the exit
code could not be determined (for example
[sigchild compatibility](#sigchild-compatibility) was disabled).
Similarly, `$term` is `null` unless the process has terminated in response to
an uncaught signal sent to it.
This is not a limitation of this project, but actual how exit codes and signals
are exposed on POSIX systems, for more details see also
[here](https://unix.stackexchange.com/questions/99112/default-exit-code-when-process-is-terminated).

It's also worth noting that process termination depends on all file descriptors
being closed beforehand.
This means that all [process pipes](#stream-properties) will emit a `close`
event before the `exit` event and that no more `data` events will arrive after
the `exit` event.
Accordingly, if either of these pipes is in a paused state (`pause()` method
or internally due to a `pipe()` call), this detection may not trigger.

The `terminate(?int $signal = null): bool` method can be used to send the
process a signal (SIGTERM by default).
Depending on which signal you send to the process and whether it has a signal
handler registered, this can be used to either merely signal a process or even
forcefully terminate it.

```php
$process->terminate(SIGUSR1);
```

Keep the above section in mind if you want to forcefully terminate a process.
If your process spawn sub-processes or implicitly uses the
[wrapping shell mentioned above](#command), its file descriptors may be
inherited to child processes and terminating the main process may not
necessarily terminate the whole process tree.
It is highly suggested that you explicitly `close()` all process pipes
accordingly when terminating a process:

```php
$process = new Process('sleep 10');
$process->start($loop);

$loop->addTimer(2.0, function () use ($process) {
    $process->stdin->close();
    $process->stout->close();
    $process->stderr->close();
    $process->terminate(SIGKILL);
});
```

For many simple programs these seamingly complicated steps can also be avoided
by prefixing the command line with `exec` to avoid the wrapping shell and its
inherited process pipes as [mentioned above](#command).

```php
$process = new Process('exec sleep 10');
$process->start($loop);

$loop->addTimer(2.0, function () use ($process) {
    $process->terminate();
});
```

Many command line programs also wait for data on `STDIN` and terminate cleanly
when this pipe is closed.
For example, the following can be used to "soft-close" a `cat` process:

```php
$process = new Process('cat');
$process->start($loop);

$loop->addTimer(2.0, function () use ($process) {
    $process->stdin->end();
});
```

While process pipes and termination may seem confusing to newcomers, the above
properties actually allow some fine grained control over process termination,
such as first trying a soft-close and then applying a force-close after a
timeout.

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

### Windows Compatibility

Due to the blocking nature of `STDIN`/`STDOUT`/`STDERR` pipes on Windows we can 
not guarantee this package works as expected on Windows directly. As such when 
instantiating `Process` it throws an exception when on native Windows. 
However this package does work on [`Windows Subsystem for Linux`](https://en.wikipedia.org/wiki/Windows_Subsystem_for_Linux) 
(or WSL) without issues. We suggest [installing WSL](https://msdn.microsoft.com/en-us/commandline/wsl/install_guide) 
when you want to run this package on Windows.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/child-process:^0.5.2
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.3 through current PHP 7+ and HHVM.
It's *highly recommended to use PHP 7+* for this project.

See above note for limited [Windows Compatibility](#windows-compatibility).

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT, see [LICENSE file](LICENSE).
