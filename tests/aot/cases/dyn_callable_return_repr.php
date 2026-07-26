<?php

// A closure parked in an UNTYPED (mixed) slot and called back through it: the
// uniform closure ABI boxes the return, so the caller must read it BY TAG. It
// used to be read raw — an int came back as int(-4222124650659798), a bool as an
// int, and a void callback's result was not === null.

final class Hook
{
    public static mixed $fn = null;

    public static function install(mixed $fn): void { self::$fn = $fn; }
    public static function get(): mixed { return self::$fn; }
}

function callThrough(int $x): mixed
{
    $h = Hook::get();
    return $h($x);
}

Hook::install(function (int $x): int { return $x + 1; });
var_dump(callThrough(41));
var_dump(callThrough(41) === 42);

Hook::install(function (int $x): float { return $x / 4.0; });
var_dump(callThrough(7));

Hook::install(function (int $x): bool { return $x > 0; });
var_dump(callThrough(7));
var_dump(callThrough(7) === true);
var_dump(callThrough(-7) === false);

Hook::install(function (int $x): string { return 'n' . (string)$x; });
var_dump(callThrough(9));

Hook::install(function (int $x): array { return ['k' => $x, 'v' => 'x']; });
$a = callThrough(3);
var_dump(is_array($a));
var_dump($a['k']);
var_dump($a['v']);

Hook::install(function (int $x): ?int { return $x > 0 ? $x : null; });
var_dump(callThrough(5));
var_dump(callThrough(-5) === null);

// A void callback: the implicit return must be a boxed null, not raw 0.
Hook::install(function (int $x): void { });
var_dump(callThrough(1) === null);
var_dump(callThrough(1));

// The same rules through a plain mixed LOCAL, not a static property.
$cb = function (string $s): string { return strtoupper($s); };
$slot = $cb;
$r = $slot('abc');
var_dump($r);
var_dump($r === 'ABC');
