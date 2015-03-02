<?php
namespace React\ChildProcess;

use React\Stream\Stream;
use React\EventLoop\LoopInterface;

/**
 * This is a special kind of stream that does not end when reaching EOF.
 * Useful for the Windows workaround of STDOUT and STDERR due to buggy PHP implementation.
 */
class UnstopableStream extends Stream
{
    private $filename;
    
    public function __construct($stream, LoopInterface $loop, $filename)
    {
        parent::__construct($stream, $loop);
        $this->filename = $filename;    
    }
    
    public function handleData($stream)
    {
        $data = fread($stream, $this->bufferSize);
    
        $this->emit('data', array($data, $this));
    
        // Let's not stop on feof
        if (!is_resource($stream)) {
            $this->end();
        }
    }

    public function handleClose()
    {
        parent::handleClose();
        unlink($this->filename);
    }
}
