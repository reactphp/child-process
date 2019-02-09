<?php

namespace React\Tests\ChildProcess;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use React\ChildProcess\Process;
use SebastianBergmann\Environment\Runtime;

abstract class AbstractProcessTest extends TestCase
{
    abstract public function createLoop();

    public function testGetCommand()
    {
        $process = new Process('echo foo', null, null, array());

        $this->assertSame('echo foo', $process->getCommand());
    }

    public function testPipesWillBeUnsetBeforeStarting()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $process = new Process('echo foo');

        $this->assertNull($process->stdin);
        $this->assertNull($process->stdout);
        $this->assertNull($process->stderr);
        $this->assertEquals(array(), $process->pipes);
    }

    /**
     * @depends testPipesWillBeUnsetBeforeStarting
     */
    public function testStartWillAssignPipes()
    {
        $process = new Process('echo foo');
        $process->start($this->createLoop());

        $this->assertInstanceOf('React\Stream\WritableStreamInterface', $process->stdin);
        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $process->stdout);
        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $process->stderr);
        $this->assertCount(3, $process->pipes);
        $this->assertSame($process->stdin, $process->pipes[0]);
        $this->assertSame($process->stdout, $process->pipes[1]);
        $this->assertSame($process->stderr, $process->pipes[2]);
    }

    public function testStartWithoutAnyPipesWillNotAssignPipes()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $process = new Process('cmd /c exit 0', null, null, array());
        } else {
            $process = new Process('exit 0', null, null, array());
        }
        $process->start($this->createLoop());

        $this->assertNull($process->stdin);
        $this->assertNull($process->stdout);
        $this->assertNull($process->stderr);
        $this->assertEquals(array(), $process->pipes);
    }

    public function testStartWithCustomPipesWillAssignPipes()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $process = new Process('exit 0', null, null, array(
            0 => array('pipe', 'w'),
            3 => array('pipe', 'r')
        ));
        $process->start($this->createLoop());

        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $process->stdin);
        $this->assertNull($process->stdout);
        $this->assertNull($process->stderr);
        $this->assertCount(2, $process->pipes);
        $this->assertSame($process->stdin, $process->pipes[0]);
        $this->assertInstanceOf('React\Stream\WritableStreamInterface', $process->pipes[3]);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage No such file or directory
     */
    public function testStartWithInvalidFileDescriptorPathWillThrow()
    {
        $fds = array(
            4 => array('file', '/dev/does-not-exist', 'r')
        );

        $process = new Process('exit 0', null, null, $fds);
        $process->start($this->createLoop());
    }

    public function testStartWithExcessiveNumberOfFileDescriptorsWillThrow()
    {
        if (PHP_VERSION_ID < 70000) {
            $this->markTestSkipped('PHP 7+ only, causes memory overflow on legacy PHP 5');
        }

        $ulimit = exec('ulimit -n 2>&1');
        if ($ulimit < 1) {
            $this->markTestSkipped('Unable to determine limit of open files (ulimit not available?)');
        }

        $loop = $this->createLoop();

        // create 70% (usually ~700) dummy file handles in this parent dummy
        $limit = (int)($ulimit * 0.7);
        $fds = array();
        for ($i = 0; $i < $limit; ++$i) {
            $fds[$i] = fopen('/dev/null', 'r');
        }

        // try to create child process with another ~700 dummy file handles
        $new = array_fill(0, $limit, array('file', '/dev/null', 'r'));
        $process = new Process('ping example.com', null, null, $new);

        try {
            $process->start($loop);

            $this->fail('Did not expect to reach this point');
        } catch (\RuntimeException $e) {
            // clear dummy files handles to make some room again (avoid fatal errors for autoloader)
            $fds = array();

            $this->assertContains('Too many open files', $e->getMessage());
        }
    }

    public function testIsRunning()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows doesn't have a sleep command and also does not support process pipes
            $process = new Process($this->getPhpBinary() . ' -r ' . escapeshellarg('sleep(1);'), null, null, array());
        } else {
            $process = new Process('sleep 1');
        }

        $this->assertFalse($process->isRunning());
        $process->start($this->createLoop());
        $this->assertTrue($process->isRunning());

        return $process;
    }

    /**
     * @depends testIsRunning
     */
    public function testGetExitCodeWhenRunning($process)
    {
        $this->assertNull($process->getExitCode());
    }

    /**
     * @depends testIsRunning
     */
    public function testGetTermSignalWhenRunning($process)
    {
        $this->assertNull($process->getTermSignal());
    }

    public function testCommandWithEnhancedSigchildCompatibilityReceivesExitCode()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $loop = $this->createLoop();
        $old = Process::isSigchildEnabled();
        Process::setSigchildEnabled(true);
        $process = new Process('echo foo');
        $process->start($loop);
        Process::setSigchildEnabled($old);

        $loop->run();

        $this->assertEquals(0, $process->getExitCode());
    }

    public function testReceivesProcessStdoutFromEcho()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = 'echo test';

        $loop = $this->createLoop();
        $process = new Process($cmd);
        $process->start($loop);

        $buffer = '';
        $process->stdout->on('data', function ($data) use (&$buffer) {
            $buffer .= $data;
        });

        $loop->run();

        $this->assertEquals('test', rtrim($buffer));
    }

    public function testReceivesProcessOutputFromStdoutRedirectedToFile()
    {
        $tmp = tmpfile();

        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = 'cmd /c echo test';
        } else {
            $cmd = 'echo test';
        }

        $loop = $this->createLoop();
        $process = new Process($cmd, null, null, array(1 => $tmp));
        $process->start($loop);

        $loop->run();

        rewind($tmp);
        $this->assertEquals('test', rtrim(stream_get_contents($tmp)));
    }

    public function testReceivesProcessOutputFromTwoCommandsChainedStdoutRedirectedToFile()
    {
        $tmp = tmpfile();

        if (DIRECTORY_SEPARATOR === '\\') {
            // omit whitespace before "&&" and quotation marks as Windows will actually echo this otherwise
            $cmd = 'cmd /c echo hello&& cmd /c echo world';
        } else {
            $cmd = 'echo "hello" && echo "world"';
        }

        $loop = $this->createLoop();
        $process = new Process($cmd, null, null, array(1 => $tmp));
        $process->start($loop);

        $loop->run();

        rewind($tmp);
        $this->assertEquals("hello\nworld", str_replace("\r\n", "\n", rtrim(stream_get_contents($tmp))));
    }

    public function testReceivesProcessOutputFromStdoutAttachedToSocket()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Sockets as STDIO handles not supported on Windows');
        }

        // create TCP/IP server on random port and create a client connection
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $client = stream_socket_client(stream_socket_get_name($server, false));
        $peer = stream_socket_accept($server, 0);
        fclose($server);

        $cmd = 'echo test';

        $loop = $this->createLoop();

        // spawn child process with $client socket as STDOUT, close local reference afterwards
        $process = new Process($cmd, null, null, array(1 => $client));
        $process->start($loop);
        fclose($client);

        $loop->run();

        $this->assertEquals('test', rtrim(stream_get_contents($peer)));
    }

    public function testReceivesProcessOutputFromStdoutRedirectedToSocketProcess()
    {
        // create TCP/IP server on random port and wait for client connection
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = 'cmd /c echo test';
        } else {
            $cmd = 'exec echo test';
        }

        $code = '$s=stream_socket_client($argv[1]);do{$d=fread(STDIN,8192);fwrite($s,$d);}while(!feof(STDIN));fclose($s);';
        $cmd .= ' | ' . $this->getPhpBinary() . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg(stream_socket_get_name($server, false));

        $loop = $this->createLoop();

        // spawn child process without any STDIO streams
        $process = new Process($cmd, null, null, array());
        $process->start($loop);

        $peer = stream_socket_accept($server, 10);

        $loop->run();

        $this->assertEquals('test', rtrim(stream_get_contents($peer)));
    }

    public function testReceivesProcessStdoutFromDd()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        if (!file_exists('/dev/zero')) {
            $this->markTestSkipped('Unable to read from /dev/zero, Windows?');
        }

        $cmd = 'dd if=/dev/zero bs=12345 count=1234';

        $loop = $this->createLoop();
        $process = new Process($cmd);
        $process->start($loop);

        $bytes = 0;
        $process->stdout->on('data', function ($data) use (&$bytes) {
            $bytes += strlen($data);
        });

        $loop->run();

        $this->assertEquals(12345 * 1234, $bytes);
    }

    public function testProcessPidNotSameDueToShellWrapper()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = $this->getPhpBinary() . ' -r ' . escapeshellarg('echo getmypid();');

        $loop = $this->createLoop();
        $process = new Process($cmd, '/');
        $process->start($loop);

        $output = '';
        $process->stdout->on('data', function ($data) use (&$output) {
            $output .= $data;
        });

        $loop->run();

        $this->assertNotEquals('', $output);
        $this->assertNotNull($process->getPid());
        $this->assertNotEquals($process->getPid(), $output);
    }

    public function testProcessPidSameWithExec()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = 'exec ' . $this->getPhpBinary() . ' -r ' . escapeshellarg('echo getmypid();');

        $loop = $this->createLoop();
        $process = new Process($cmd, '/');
        $process->start($loop);

        $output = '';
        $process->stdout->on('data', function ($data) use (&$output) {
            $output .= $data;
        });

        $loop->run();

        $this->assertNotNull($process->getPid());
        $this->assertEquals($process->getPid(), $output);
    }

    public function testProcessWithDefaultCwdAndEnv()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = $this->getPhpBinary() . ' -r ' . escapeshellarg('echo getcwd(), PHP_EOL, count($_SERVER), PHP_EOL;');

        $loop = $this->createLoop();

        $process = new Process($cmd);
        $process->start($loop);

        $output = '';
        $process->stdout->on('data', function () use (&$output) {
            $output .= func_get_arg(0);
        });

        $loop->run();

        list($cwd, $envCount) = explode(PHP_EOL, $output);

        /* Child process should inherit the same current working directory and
         * existing environment variables; however, it may be missing a "_"
         * environment variable (i.e. current shell/script) on some platforms.
         */
        $this->assertSame(getcwd(), $cwd);
        $this->assertLessThanOrEqual(1, (count($_SERVER) - (integer) $envCount));
    }

    public function testProcessWithCwd()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = $this->getPhpBinary() . ' -r ' . escapeshellarg('echo getcwd(), PHP_EOL;');

        $loop = $this->createLoop();

        $process = new Process($cmd, '/');
        $process->start($loop);

        $output = '';
        $process->stdout->on('data', function () use (&$output) {
            $output .= func_get_arg(0);
        });

        $loop->run();

        $this->assertSame('/' . PHP_EOL, $output);
    }

    public function testProcessWithEnv()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        if (getenv('TRAVIS')) {
            $this->markTestSkipped('Cannot execute PHP processes with custom environments on Travis CI.');
        }

        $cmd = $this->getPhpBinary() . ' -r ' . escapeshellarg('echo getenv("foo"), PHP_EOL;');

        $loop = $this->createLoop();

        $process = new Process($cmd, null, array('foo' => 'bar'));
        $process->start($loop);

        $output = '';
        $process->stdout->on('data', function () use (&$output) {
            $output .= func_get_arg(0);
        });

        $loop->run();

        $this->assertSame('bar' . PHP_EOL, $output);
    }

    public function testStartAndAllowProcessToExitSuccessfullyUsingEventLoop()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $loop = $this->createLoop();
        $process = new Process('exit 0');

        $called = false;
        $exitCode = 'initial';
        $termSignal = 'initial';

        $process->on('exit', function () use (&$called, &$exitCode, &$termSignal) {
            $called = true;
            $exitCode = func_get_arg(0);
            $termSignal = func_get_arg(1);
        });

        $process->start($loop);

        $loop->run();

        $this->assertTrue($called);
        $this->assertSame(0, $exitCode);
        $this->assertNull($termSignal);

        $this->assertFalse($process->isRunning());
        $this->assertSame(0, $process->getExitCode());
        $this->assertNull($process->getTermSignal());
        $this->assertFalse($process->isTerminated());
    }

    public function testProcessWillExitFasterThanExitInterval()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $loop = $this->createLoop();
        $process = new Process('echo hi');
        $process->start($loop, 2);

        $time = microtime(true);
        $loop->run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.1, $time);
    }

    public function testDetectsClosingStdoutWithoutHavingToWaitForExit()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = 'exec ' . $this->getPhpBinary() . ' -r ' . escapeshellarg('fclose(STDOUT); sleep(1);');

        $loop = $this->createLoop();
        $process = new Process($cmd);
        $process->start($loop);

        $closed = false;
        $process->stdout->on('close', function () use (&$closed, $loop) {
            $closed = true;
            $loop->stop();
        });

        // run loop for maximum of 0.5s only
        $loop->addTimer(0.5, function () use ($loop) {
            $loop->stop();
        });
        $loop->run();

        $this->assertTrue($closed);
    }

    public function testKeepsRunningEvenWhenAllStdioPipesHaveBeenClosed()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = 'exec ' . $this->getPhpBinary() . ' -r ' . escapeshellarg('fclose(STDIN);fclose(STDOUT);fclose(STDERR);sleep(1);');

        $loop = $this->createLoop();
        $process = new Process($cmd);
        $process->start($loop);

        $closed = 0;
        $process->stdout->on('close', function () use (&$closed, $loop) {
            ++$closed;
            if ($closed === 2) {
                $loop->stop();
            }
        });
        $process->stderr->on('close', function () use (&$closed, $loop) {
            ++$closed;
            if ($closed === 2) {
                $loop->stop();
            }
        });

        // run loop for maximum 0.5s only
        $loop->addTimer(0.5, function () use ($loop) {
            $loop->stop();
        });
        $loop->run();

        $this->assertEquals(2, $closed);
        $this->assertTrue($process->isRunning());
    }

    public function testDetectsClosingProcessEvenWhenAllStdioPipesHaveBeenClosed()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = 'exec ' . $this->getPhpBinary() . ' -r ' . escapeshellarg('fclose(STDIN);fclose(STDOUT);fclose(STDERR);usleep(10000);');

        $loop = $this->createLoop();
        $process = new Process($cmd);
        $process->start($loop, 0.001);

        $time = microtime(true);
        $loop->run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.5, $time);
        $this->assertSame(0, $process->getExitCode());
    }

    public function testDetectsClosingProcessEvenWhenStartedWithoutPipes()
    {
        $loop = $this->createLoop();

        if (DIRECTORY_SEPARATOR === '\\') {
            $process = new Process('cmd /c exit 0', null, null, array());
        } else {
            $process = new Process('exit 0', null, null, array());
        }

        $process->start($loop, 0.001);

        $time = microtime(true);
        $loop->run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.1, $time);
        $this->assertSame(0, $process->getExitCode());
    }

    public function testStartInvalidProcess()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $cmd = tempnam(sys_get_temp_dir(), 'react');

        $loop = $this->createLoop();

        $process = new Process($cmd);
        $process->start($loop);

        $output = '';
        $process->stderr->on('data', function () use (&$output) {
            $output .= func_get_arg(0);
        });

        $loop->run();

        unlink($cmd);

        $this->assertNotEmpty($output);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testStartAlreadyRunningProcess()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows doesn't have a sleep command and also does not support process pipes
            $process = new Process($this->getPhpBinary() . ' -r ' . escapeshellarg('sleep(1);'), null, null, array());
        } else {
            $process = new Process('sleep 1');
        }
        //var_dump($process);

        $process->start($this->createLoop());
        $process->start($this->createLoop());
    }

    public function testTerminateProcesWithoutStartingReturnsFalse()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows doesn't have a sleep command and also does not support process pipes
            $process = new Process($this->getPhpBinary() . ' -r ' . escapeshellarg('sleep(1);'), null, null, array());
        } else {
            $process = new Process('sleep 1');
        }

        $this->assertFalse($process->terminate());
    }

    public function testTerminateWillExit()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows doesn't have a sleep command and also does not support process pipes
            $process = new Process($this->getPhpBinary() . ' -r ' . escapeshellarg('sleep(10);'), null, null, array());
        } else {
            $process = new Process('sleep 10');
        }

        $loop = $this->createloop();

        $process->start($loop);

        $called = false;
        $process->on('exit', function () use (&$called) {
            $called = true;
        });

        foreach ($process->pipes as $pipe) {
            $pipe->close();
        }
        $process->terminate();

        $loop->run();

        $this->assertTrue($called);
    }

    public function testTerminateWithDefaultTermSignalUsingEventLoop()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows does not report signals via proc_get_status()');
        }

        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM is not defined');
        }

        $loop = $this->createloop();
        $process = new Process('sleep 1; exit 0');

        $called = false;
        $exitCode = 'initial';
        $termSignal = 'initial';

        $process->on('exit', function () use (&$called, &$exitCode, &$termSignal) {
            $called = true;
            $exitCode = func_get_arg(0);
            $termSignal = func_get_arg(1);
        });

        $process->start($loop);
        $process->terminate();

        $loop->run();

        $this->assertTrue($called);
        $this->assertNull($exitCode);
        $this->assertEquals(SIGTERM, $termSignal);

        $this->assertFalse($process->isRunning());
        $this->assertNull($process->getExitCode());
        $this->assertEquals(SIGTERM, $process->getTermSignal());
        $this->assertTrue($process->isTerminated());
    }

    public function testTerminateWithStopAndContinueSignalsUsingEventLoop()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows does not report signals via proc_get_status()');
        }

        if (!defined('SIGSTOP') && !defined('SIGCONT')) {
            $this->markTestSkipped('SIGSTOP and/or SIGCONT is not defined');
        }

        $loop = $this->createloop();
        $process = new Process('sleep 1; exit 0');

        $called = false;
        $exitCode = 'initial';
        $termSignal = 'initial';

        $process->on('exit', function () use (&$called, &$exitCode, &$termSignal) {
            $called = true;
            $exitCode = func_get_arg(0);
            $termSignal = func_get_arg(1);
        });

        $that = $this;
        $process->start($loop);
        $process->terminate(SIGSTOP);

        $that->assertSoon(function () use ($process, $that) {
            $that->assertTrue($process->isStopped());
            $that->assertTrue($process->isRunning());
            $that->assertEquals(SIGSTOP, $process->getStopSignal());
        });

        $process->terminate(SIGCONT);

        $that->assertSoon(function () use ($process, $that) {
            $that->assertFalse($process->isStopped());
            $that->assertEquals(SIGSTOP, $process->getStopSignal());
        });

        $loop->run();

        $this->assertTrue($called);
        $this->assertSame(0, $exitCode);
        $this->assertNull($termSignal);

        $this->assertFalse($process->isRunning());
        $this->assertSame(0, $process->getExitCode());
        $this->assertNull($process->getTermSignal());
        $this->assertFalse($process->isTerminated());
    }

    public function testIssue18()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Process pipes not supported on Windows');
        }

        $loop = $this->createLoop();

        $testString = 'x';

        $process = new Process($this->getPhpBinary() . " -r 'echo \"$testString\";'");

        $stdOut = '';
        $stdErr = '';

        $that = $this;
        $process->on(
            'exit',
            function ($exitCode) use (&$stdOut, &$stdErr, $testString, $that) {
                $that->assertEquals(0, $exitCode, "Exit code is 0");

                $that->assertEquals($testString, $stdOut);
            }
        );

        $process->start($loop);

        $process->stdout->on(
            'data',
            function ($output) use (&$stdOut) {
                $stdOut .= $output;
            }
        );
        $process->stderr->on(
            'data',
            function ($output) use (&$stdErr) {
                $stdErr .= $output;
            }
        );

        // tick loop once
        $loop->addTimer(0, function () use ($loop) {
            $loop->stop();
        });
        $loop->run();

        sleep(1); // comment this line out and it works fine

        $loop->run();
    }

    /**
     * Execute a callback at regular intervals until it returns successfully or
     * a timeout is reached.
     *
     * @param \Closure $callback Callback with one or more assertions
     * @param integer $timeout  Time limit for callback to succeed (milliseconds)
     * @param integer $interval Interval for retrying the callback (milliseconds)
     * @throws PHPUnit_Framework_ExpectationFailedException Last exception raised by the callback
     */
    public function assertSoon(\Closure $callback, $timeout = 20000, $interval = 200)
    {
        $start = microtime(true);
        $timeout /= 1000; // convert to seconds
        $interval *= 1000; // convert to microseconds

        while (1) {
            try {
                call_user_func($callback);
                return;
            } catch (ExpectationFailedException $e) {
                // namespaced PHPUnit exception
            } catch (\PHPUnit_Framework_ExpectationFailedException $e) {
                // legacy PHPUnit exception
            }

            if ((microtime(true) - $start) > $timeout) {
                throw $e;
            }

            usleep($interval);
        }
    }

    /**
     * Returns the path to the PHP binary. This is already escapescaped via `escapeshellarg()`.
     *
     * @return string
     */
    private function getPhpBinary()
    {
        $runtime = new Runtime();

        return $runtime->getBinary();
    }
}
