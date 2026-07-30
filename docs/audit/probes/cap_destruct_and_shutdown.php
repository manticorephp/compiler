<?php
// @epic: lifecycle
// @why: Kernel::terminate, doctrine's flush-on-shutdown and monolog's buffer
//       flush all depend on destructor + shutdown-function ordering. Getting the
//       order wrong loses writes silently.

final class CapRes
{
    public function __construct(private string $tag) {}
    public function __destruct() { echo "destruct {$this->tag}\n"; }
}

register_shutdown_function(function () { echo "shutdown 1\n"; });
register_shutdown_function(function (string $a) { echo "shutdown 2 $a\n"; }, 'arg');

$keep = new CapRes('kept');
$drop = new CapRes('dropped');
unset($drop);
echo "after unset\n";
