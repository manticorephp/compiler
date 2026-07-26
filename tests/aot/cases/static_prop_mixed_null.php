<?php
// A `mixed` static property carries its type in a NaN tag, so its null is the
// BOXED null — a link-time initialiser of raw 0 read back as int(0) and made
// `=== null` FALSE for a property nobody had assigned. The classic victim is an
// optional-callback slot: the guard passes and the call goes through null.
final class Hooks
{
    public static mixed $cb = null;
    public static ?string $name = null;
    public static int $n = 0;

    public static function cb(): mixed { return self::$cb; }
    public static function set(mixed $v): void { self::$cb = $v; }
}

var_dump(Hooks::$cb === null);
var_dump(Hooks::$cb !== null);
var_dump(Hooks::cb() === null);
var_dump(Hooks::$name === null);
var_dump(Hooks::$n);
var_dump(is_null(Hooks::$cb));

Hooks::set(42);
var_dump(Hooks::$cb === null);
var_dump(Hooks::$cb);

Hooks::set(null);
var_dump(Hooks::$cb === null);
