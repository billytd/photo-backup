<?php

require_once 'WriterInterface.php';

class TextWriter implements WriterInterface {
    public function writeMessage($message)
    {
        echo "$message\n";
    }
}