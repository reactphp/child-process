# Child Process Component

[![Build Status](https://secure.travis-ci.org/reactphp/child-process.png?branch=master)](http://travis-ci.org/reactphp/child-process) [![Code Climate](https://codeclimate.com/github/reactphp/child-process/badges/gpa.svg)](https://codeclimate.com/github/reactphp/child-process)

Library for executing child processes.

## Introduction

This library integrates the
[Program Execution](http://php.net/manual/en/book.exec.php) extension in PHP
with React's event loop.

Child processes launched within the event loop may be signaled and will emit an
`exit` event upon termination. Additionally, process I/O streams (i.e. stdin,
stdout, stderr) are registered with the loop.

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

## Usage
```php
    $loop = React\EventLoop\Factory::create();

    $process = new React\ChildProcess\Process('echo foo');

    $process->on('exit', function($exitCode, $termSignal) {
        // ...
    });

    $loop->addTimer(0.001, function($timer) use ($process) {
        $process->start($timer->getLoop());

        $process->stdout->on('data', function($output) {
            // ...
        });
    });

    $loop->run();
```
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

### Windows compatibility

Windows has always had a poor `proc_open` implementation in PHP. Even if things
are better with the latest PHP versions, there are still a number of issues
when programs are outputing values to STDOUT or STDERR (truncated output,
deadlocks, ...).
Prior to PHP 5.5.18 or PHP 5.6.3, a bug was simply causing a deadlock on
Windows, making `proc_open` unusable.
After PHP 5.5.18 and 5.6.3. Still, if the process
you call outputs more than 4096 bytes, there are chances that your output will
be truncated, or that your PHP process will stall for many seconds.

Note: at the time of this writing, the bugs are still present in the current
version of PHP, which arePHP 5.5.22 and PHP 5.6.6.

To circumvent these problems, instead of relying on STDOUT
or STDERR, *child-process* comes with a *Windows workaround* mode. You
must activate it explicitly using the `useWindowsWorkaround` method.
When activiated, it will redirect the output (STDOUT and STDERR) to files
(in the temporary folder). This is an implementation detail but it is important
to be aware of it. Indeed, the output of the command will be written to the
disk, and even if the file is deleted at the end of the process, writing the
output to the disk might be a security issues if the output contains sensitive
data.
It could also be an issue with long lasting commands that are outputing a lot
of data since the output file will grow until the process ends. This might
fill the hard-drive if the process lasts long enough.

```php
    $process = new React\ChildProcess\Process('echo foo');
    $process->useWindowsWorkaround(true);
    ...
```

Note: the `useWindowsWorkaround` is only used on Windows, it has no effect on
other operating systems, so you can use it safely on Linux or MacOS.
