<?php
function mk(string $k): string { return "item:" . $k; }
final class A { public static $create; public function get(string $key, callable $callback): mixed { $save = null; return $callback((self::$create)($key), $save); } }
function cb2(string $s, ?bool &$save): string { $save = false; return strtoupper($s); }
class Inv { public function __invoke($s, &$save) { return "inv " . $s; } }
A::$create = static fn ($k) => "ci(" . $k . ")";
$a = new A();
echo $a->get("x", fn ($i, $s) => $i . "!"), "\n";
echo $a->get("y", "cb2"), "\n";
echo $a->get("z", new Inv()), "\n";
echo $a->get("w", [new Inv(), "__invoke"]), "\n";
$f = "mk"; echo $f(str_repeat("ab", 2) . "c"), "\n";
