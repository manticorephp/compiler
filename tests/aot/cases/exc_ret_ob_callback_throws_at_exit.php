<?php
// An exception thrown by an output-buffer callback while the buffers drain at exit reaches the exception handler.
set_exception_handler(function ($e) { echo "handler: ", $e->getMessage(), "\n"; });
ob_start(function ($buf) { throw new RuntimeException('from ob'); });
echo "main\n";
