<?php
// null into a non-nullable string parameter of an internal function: php 8 deprecates and
// coerces, the project throws php 9's TypeError (#751). A non-null ?string passes through.
function len(?string $s): int { return strlen($s); }
function sub(?string $s): string { return substr($s, 0, 3); }
function up(?string $s): string { return strtoupper($s); }
function t(callable $f): void
{
    try { $r = $f(); echo "[", $r, "]\n"; } catch (\TypeError $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
t(fn () => len(null));
t(fn () => len("hello"));
t(fn () => sub(null));
t(fn () => sub("hello"));
t(fn () => up(null));
t(fn () => up("abc"));
