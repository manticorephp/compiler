<?php
function k() { echo "k"; return 'a'; }
function k2() { echo "k2"; return 1; }
$m = ['a' => 1];
echo $m[k()] ?? 0, "\n";
var_dump(isset($m[k()]));
$n = ['a' => null];
var_dump($n['a'] ?? 'd', isset($n['a']));
$l = [1, 2];
var_dump($l[k2()] ?? 'x');
function g(mixed $x) { return $x['a'] ?? 'z'; }
var_dump(g(['a' => 5]), g([]), g(null));
$s = 'abc';
var_dump($s[1] ?? 'q', $s[9] ?? 'q');
class O implements ArrayAccess {
    public function offsetExists(mixed $o): bool { echo "exists($o) "; return true; }
    public function offsetGet(mixed $o): mixed { echo "get($o) "; return 7; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void {}
}
$o = new O;
var_dump(isset($o['x']));
var_dump($o['x'] ?? 1);
