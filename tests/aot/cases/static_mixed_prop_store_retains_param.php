<?php
// A `mixed` param stored into a `mixed` static property is co-owned by the slot: the caller's release of its temporary must not free it.
final class Hook
{
    public static mixed $fn = null;
    public static function install(mixed $fn): void { self::$fn = $fn; }
    public static function get(): mixed { return self::$fn; }
}
function callThrough(int $x): mixed { $h = Hook::get(); return $h($x); }
function setup(int $y): void { $f = function (int $x) use ($y): int { return $x + $y; }; Hook::install($f); }
setup(5);
$junk = [str_repeat('q', 40), str_repeat('w', 40)];
var_dump(callThrough(41));
Hook::install('s' . mt_rand(1, 1));
$junk = [str_repeat('q', 40), str_repeat('w', 40)];
var_dump(Hook::get());
function churn(int $n): void { for ($i = 0; $i < $n; $i++) { Hook::install(static fn (int $x): int => $x + $i); Hook::install('v' . $i); } }
churn(2000);
$before = memory_get_peak_usage();
churn(100000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
