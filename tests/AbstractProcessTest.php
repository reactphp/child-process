<?php

namespace React\Tests\ChildProcess;

use React\ChildProcess\Process;
use React\EventLoop\Timer\Timer;
use SebastianBergmann\Environment\Runtime;

abstract class AbstractProcessTest extends \PHPUnit_Framework_TestCase
{
    abstract public function createLoop();

    public function testGetEnhanceSigchildCompatibility()
    {
        $process = new Process('echo foo');

        $this->assertSame($process, $process->setEnhanceSigchildCompatibility(true));
        $this->assertTrue($process->getEnhanceSigchildCompatibility());

        $this->assertSame($process, $process->setEnhanceSigchildCompatibility(false));
        $this->assertFalse($process->getEnhanceSigchildCompatibility());
    }

    /**
     * @expectedException RuntimeException
     */
    public function testSetEnhanceSigchildCompatibilityCannotBeCalledIfProcessIsRunning()
    {
        $process = new Process('sleep 1');

        $process->start($this->createLoop());
        $process->setEnhanceSigchildCompatibility(false);
    }

    public function testGetCommand()
    {
        $process = new Process('echo foo');

        $this->assertSame('echo foo', $process->getCommand());
    }

    public function testIsRunning()
    {
        $process = new Process('sleep 1');

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

    public function testProcessWithDefaultCwdAndEnv()
    {
        $cmd = $this->getPhpCommandLine('echo getcwd(), PHP_EOL, count($_SERVER), PHP_EOL;');

        $loop = $this->createLoop();
        $process = new Process($cmd);

        $output = '';
        $error = '';

        $loop->addTimer(0.001, function(Timer $timer) use ($process, &$output, &$error) {
            $process->start($timer->getLoop());
            $process->stdout->on('data', function ($data) use (&$output) {
                $output .= $data;
            });
            $process->stderr->on('data', function ($data) use (&$error) {
                $error .= $data;
            });
        });

        $loop->run();

        $this->assertEmpty($error);
        $this->assertNotEmpty($output);

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
        $cmd = $this->getPhpCommandLine('echo getcwd(), PHP_EOL;');

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $testCwd = 'C:\\';
        } else {
        	$testCwd = '/';
        }

        $loop = $this->createLoop();
        $process = new Process($cmd, $testCwd);

        $output = '';

        $loop->addTimer(0.001, function(Timer $timer) use ($process, &$output) {
            $process->start($timer->getLoop());
            $process->stdout->on('data', function () use (&$output) {
                $output .= func_get_arg(0);
            });
        });

        $loop->run();

        $this->assertSame($testCwd . PHP_EOL, $output);
    }

    public function testProcessWithEnv()
    {
        if (getenv('TRAVIS')) {
            $this->markTestSkipped('Cannot execute PHP processes with custom environments on Travis CI.');
        }

        $cmd = $this->getPhpCommandLine('echo getenv("foo"), PHP_EOL;');

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            // Windows madness! escapeshellarg seems to completely remove double quotes in Windows!
            // We need to use simple quotes in our PHP code!
            $cmd = $this->getPhpCommandLine('echo getenv(\'foo\'), PHP_EOL;');
        }

        $loop = $this->createLoop();
        $process = new Process($cmd, null, array('foo' => 'bar'));

        $output = '';

        $loop->addTimer(0.001, function(Timer $timer) use ($process, &$output) {
            $process->start($timer->getLoop());
            $process->stdout->on('data', function () use (&$output) {
                $output .= func_get_arg(0);
            });
        });

        $loop->run();

        $this->assertSame('bar' . PHP_EOL, $output);
    }

    public function testStartAndAllowProcessToExitSuccessfullyUsingEventLoop()
    {
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

        $loop->addTimer(0.001, function(Timer $timer) use ($process) {
            $process->start($timer->getLoop());
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

    public function testStartInvalidProcess()
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('Windows does not have an executable flag. This test does not make sense on Windows.');
        }

        $cmd = tempnam(sys_get_temp_dir(), 'react');

        $loop = $this->createLoop();
        $process = new Process($cmd);

        $output = '';

        $loop->addTimer(0.001, function(Timer $timer) use ($process, &$output) {
            $process->start($timer->getLoop());
            $process->stderr->on('data', function () use (&$output) {
                $output .= func_get_arg(0);
            });
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
        $process = new Process('sleep 1');

        $process->start($this->createLoop());
        $process->start($this->createLoop());
    }

    public function testTerminateWithDefaultTermSignalUsingEventLoop()
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
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

        $loop->addTimer(0.001, function(Timer $timer) use ($process) {
            $process->start($timer->getLoop());
            $process->terminate();
        });

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
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
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

        $loop->addTimer(0.001, function(Timer $timer) use ($process) {
            $process->start($timer->getLoop());
            $process->terminate(SIGSTOP);

            $this->assertSoon(function() use ($process) {
                $this->assertTrue($process->isStopped());
                $this->assertTrue($process->isRunning());
                $this->assertEquals(SIGSTOP, $process->getStopSignal());
            });

            $process->terminate(SIGCONT);

            $this->assertSoon(function() use ($process) {
                $this->assertFalse($process->isStopped());
                $this->assertEquals(SIGSTOP, $process->getStopSignal());
            });
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

    public function outputSizeProvider() {
        return [ [1000, 5], [10000, 5], [100000, 5] ];
    }

    /**
     * @dataProvider outputSizeProvider
     */
    public function testProcessOutputOfSize($size, $expectedMaxDuration = 5)
    {
    	// Note: very strange behaviour of Windows (PHP 5.5.6):
    	// on a 1000 long string, Windows succeeds.
    	// on a 10000 long string, Windows fails to output anything.
    	// On a 100000 long string, it takes a lot of time but succeeds.
        $cmd = $this->getPhpBinary() . ' -r ' . escapeshellarg('echo str_repeat(\'o\', '.$size.'), PHP_EOL;');

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            // Windows madness! for some obscure reason, the whole command lines needs to be
            // wrapped in quotes (?!?)
            $cmd = '"'.$cmd.'"';
        }

        $loop = $this->createLoop();
        $process = new Process($cmd);

        $output = '';

        $loop->addTimer(0.001, function(Timer $timer) use ($process, &$output) {
            $process->start($timer->getLoop());
            $process->stdout->on('data', function () use (&$output) {
                $output .= func_get_arg(0);
            });
        });

        $startTime = time();

        $loop->run();

        $endTime = time();

        $this->assertEquals($size + strlen(PHP_EOL), strlen($output));
        $this->assertSame(str_repeat('o', $size) . PHP_EOL, $output);
        $this->assertLessThanOrEqual($expectedMaxDuration, $endTime - $startTime, "Process took longer than expected.");
    }


    /**
     * Execute a callback at regular intervals until it returns successfully or
     * a timeout is reached.
     *
     * @param Closure $callback Callback with one or more assertions
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
            } catch (\PHPUnit_Framework_ExpectationFailedException $e) {}

            if ((microtime(true) - $start) > $timeout) {
                throw $e;
            }

            usleep($interval);
        }
    }

    private function getPhpBinary()
    {
        $runtime = new Runtime();

        return $runtime->getBinary();
    }

    private function getPhpCommandLine($phpCode)
    {
        return $this->getPhpBinary() . ' -r ' . escapeshellarg($phpCode);
    }
}
