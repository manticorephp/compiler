<?php

// set_exception_handler receives the uncaught Throwable, php prints nothing of
// its own, shutdown functions still run, and the exit status is 255.

register_shutdown_function(function (): void { echo "shutdown after uncaught\n"; });

set_exception_handler(function (\Throwable $e): void {
    echo "caught by handler: ", $e->getMessage(), "\n";
});

echo "before throw\n";
throw new \RuntimeException("boom");
