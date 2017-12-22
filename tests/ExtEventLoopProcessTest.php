<?php

namespace React\Tests\ChildProcess;

use React\EventLoop\ExtEventLoop;

class ExtEventLoopProcessTest extends AbstractProcessTest
{
    public function createLoop()
    {
        if (!extension_loaded('event')) {
            $this->markTestSkipped('ext-event is not installed.');
        }
        if (!class_exists('React\EventLoop\ExtEventLoop')) {
            $this->markTestSkipped('ext-event not supported by this legacy react/event-loop version');
        }

        return new ExtEventLoop();
    }
}
