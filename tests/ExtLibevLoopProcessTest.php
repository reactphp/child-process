<?php

namespace React\Tests\ChildProcess;

use React\EventLoop\ExtLibevLoop;
use React\EventLoop\LibEvLoop;

class ExtLibevLoopProcessTest extends AbstractProcessTest
{
    public function createLoop()
    {
        if (!class_exists('libev\EventLoop')) {
            $this->markTestSkipped('ext-libev is not installed.');
        }

        return class_exists('React\EventLoop\ExtLibevLoop') ? new ExtLibevLoop() : new LibEvLoop();
    }
}
