<?php

namespace React\Tests\ChildProcess;

use React\EventLoop\ExtLibeventLoop;
use React\EventLoop\LibEventLoop;

class ExtLibeventLoopProcessTest extends AbstractProcessTest
{
    public function createLoop()
    {
        if (!function_exists('event_base_new')) {
            $this->markTestSkipped('ext-libevent is not installed.');
        }

        return class_exists('React\EventLoop\ExtLibeventLoop') ? new ExtLibeventLoop() : new LibEventLoop();
    }
}
