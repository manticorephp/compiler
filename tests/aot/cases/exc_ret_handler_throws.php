<?php
// An exception thrown by the user's exception handler is itself uncaught: php
// prints its fatal and still runs shutdown functions.
register_shutdown_function(function () { echo "shutdown\n"; exit(0); });
set_exception_handler(function ($e) {
    echo "h ", $e->getMessage(), "\n";
    throw new RuntimeException('again');
});
echo "start\n";
throw new Exception('first');
