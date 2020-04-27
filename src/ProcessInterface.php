<?php

namespace React\ChildProcess;

use Evenement\EventEmitterInterface;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * This process interface defines a common public interface
 * for all child process implementations to guarantee
 * inter-compatibility.
 *
 * This interface does not define how a process is created,
 * instead it defines a layer how to interact with it.
 *
 * The event emitter interface is used to emit events to be able
 * to react to events (such as process exit).
 *
 * The minimum required event is the `exit` event, that
 * notifies the user of the death of a child process.
 * The arguments are the exit code and termination signal
 * (as nullable integers).
 */
interface ProcessInterface extends EventEmitterInterface
{
    /**
     * Get the stdin pipe stream of the process, or null if none created.
     *
     * @return WritableStreamInterface|DuplexStreamInterface|null
     */
    public function getStdin();

    /**
     * Get the stdout pipe stream of the process, or null if none created.
     *
     * @return ReadableStreamInterface|DuplexStreamInterface|null
     */
    public function getStdout();

    /**
     * Get the stderr pipe stream of the process, or null if none created.
     *
     * @return ReadableStreamInterface|DuplexStreamInterface|null
     */
    public function getStderr();

    /**
     * Get all created pipes as array.
     *
     * @return ReadableStreamInterface[]|WritableStreamInterface[]|DuplexStreamInterface[]
     */
    public function getPipes();

    /**
     * Starts the process.
     *
     * @param LoopInterface $loop
     * @return void
     */
    public function start(LoopInterface $loop);

    /**
     * Terminate the process with an optional signal.
     *
     * @param int $signal Optional signal (default: SIGTERM).
     * @return bool  Whether the signal was sent.
     */
    public function terminate($signal = 15);

    /**
     * Get the process ID, or null if not started or terminated.
     *
     * @return int|null
     */
    public function getPid();

    /**
     * Get the exit code returned by the process.
     *
     * @return int|null
     */
    public function getExitCode();

    /**
     * Get the signal that caused the process to terminate its execution.
     *
     * @return int|null
     */
    public function getTermSignal();

    /**
     * Returns whether the process is still running.
     *
     * @return bool
     */
    public function isRunning();

    /**
     * Returns whether the process has been terminated by an uncaught signal.
     *
     * @return bool
     */
    public function isTerminated();
}
