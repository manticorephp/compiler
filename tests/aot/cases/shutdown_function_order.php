<?php

// register_shutdown_function: FIFO order, runs after the last statement, and a
// shutdown function may register another one (php runs those too).

class Closer
{
    public function __construct(public string $name) {}
    public function close(): void { echo "closed ", $this->name, "\n"; }
}

register_shutdown_function(function (): void { echo "first\n"; });
register_shutdown_function(function (): void { echo "second\n"; });

$c = new Closer("db");
register_shutdown_function([$c, "close"]);

function named_shutdown(): void
{
    echo "named\n";
    register_shutdown_function(function (): void { echo "late\n"; });
}
register_shutdown_function("named_shutdown");

echo "body done\n";
