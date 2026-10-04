<?php

namespace Acme;

// Superglobals are ONE slot across the library and the application: whatever
// either side stores, the other reads and overwrites.
final class Gpc
{
    public static function set(string $k, string $v): void { $_GET[$k] = $v; }

    public static function keys(): string { return \implode(',', \array_keys($_GET)); }

    public static function reset(int $i): void { $_GET = ['lib' . $i => \str_repeat('l', 64)]; }
}
