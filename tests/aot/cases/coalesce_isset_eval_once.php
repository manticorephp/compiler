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

// string base: base and key evaluated once
function sb() { echo "sb "; return 'abc'; }
function si() { echo "si "; return 1; }
var_dump(sb()[si()] ?? 'q');
var_dump(sb()[9] ?? 'q');
// ArrayAccess base: offsetExists then offsetGet, each once; base and key once
function ob() { global $o; echo "ob "; return $o; }
function ok_() { echo "ok "; return 'x'; }
var_dump(ob()[ok_()] ?? 1);
// erased base and key
function eb(mixed $v) { echo "eb "; return $v; }
var_dump(eb(['a' => 4])[k()] ?? 'z');
var_dump(eb([])[k()] ?? 'z');
var_dump(eb(null)[k()] ?? 'z');
// php evaluates the key before it fetches a plain variable base
function rw() { global $a; $a = [9]; return 0; }
$a = [1];
var_dump($a[rw()] ?? 0);
$a = [1];
var_dump(isset($a[rw()]));
// numeric-string keys normalise to int keys
$h = [5 => 'a', 7 => 'b', 'x' => 'c'];
$s5 = '5';
var_dump(isset($h['5']), isset($h[$s5]), $h['5'] ?? 'm', $h[$s5] ?? 'm', isset($h['05']), $h['05'] ?? 'm');
$pl = [10, 20];
$s1 = '1';
var_dump(isset($pl[$s1]), $pl[$s1] ?? 'm');
// a raw int element whose bits equal the boxed NULL word is present
$r = [10, -3659174697238528];
var_dump(isset($r[1]), $r[1] ?? 'm');
