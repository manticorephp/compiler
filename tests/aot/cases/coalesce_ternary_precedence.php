<?php
// `??` binds tighter than `?:` — the ternary tests the coalesced value.
$a = ['x' => 1, 'z' => 0]; $n = null;
echo ($a['x'] ?? false ? 'T' : 'F'), ($n ?? false ? 'T' : 'F'), ($a['z'] ?? 1 ? 'T' : 'F'), ($a['q'] ?? 'v' ? 'T' : 'F'), "\n";
echo json_encode($a['x'] ?? false ? [1] : []), json_encode($n ?? false ? [1] : []), "\n";
// right-associative chain, and an assignment as the right operand
echo $n ?? $a['q'] ?? $a['x'] ?? 9, "\n";
class Memo { private static $d; static function get() { return self::$d ?? self::$d = 'built'; } }
echo Memo::get(), Memo::get(), "\n";
$m = null; $r = $m ?? $m = 5; echo $r, $m, "\n";
// `and` / `or` stay looser, `|>` looser, nested ternary in the else arm
var_dump($n ?? 1 and 0);
echo $n ?? 'd' |> strtoupper(...), "\n";
echo ($a['x'] ?? 0) ? ($n ?? 1 ? 'a' : 'b') : 'c', "\n";
function take(array $p): int { return count($p); }
class P {}
function f(array $c) { return take($c['x'] ?? false ? [new P] : []); }
echo f([]), f(['x' => 1]), "\n";
