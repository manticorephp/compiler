<?php
// An exception thrown by a shutdown function reaches the exception handler, and the shutdown functions after it still run.
set_exception_handler(function ($e) { echo "handler: ", $e->getMessage(), "\n"; });
register_shutdown_function(function () { echo "s1\n"; throw new RuntimeException('from s1'); });
register_shutdown_function(function () { echo "s2\n"; });
echo "main\n";
