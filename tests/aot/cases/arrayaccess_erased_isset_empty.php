<?php
// ArrayAccess through a `mixed` value: isset() / ?? call offsetExists (they took the array path
// and answered false), empty() is offsetExists-then-offsetGet, and var_dump() evaluates every
// argument before printing the first.
class A implements ArrayAccess { public function offsetExists(mixed $o): bool { echo "exists($o) "; return $o !== "zz"; } public function offsetGet(mixed $o): mixed { echo "get($o) "; return $o === "a" ? 1 : 0; } public function offsetSet(mixed $o, mixed $v): void {} public function offsetUnset(mixed $o): void {} }
function mk(int $n): mixed { return $n > 0 ? new A : [1]; }
$c = mk(1); $d = new A; $arr = ["a" => 1, "z" => 0, "n" => null];
foreach (["a", "b", "zz"] as $k) { var_dump(empty($c[$k]), empty($d[$k])); }
foreach (["a", "z", "n", "q"] as $k) { var_dump(empty($arr[$k])); }
$s = "ab"; var_dump(empty($s[0]), empty($s[5]));
function f(int $i): int { echo "f$i "; return $i; }
var_dump(f(1), f(2));
$e = mk(1); var_dump(isset($e["a"]), isset($e["zz"]), $e["a"] ?? "none", $e["zz"] ?? "none");
