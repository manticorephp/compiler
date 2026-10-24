<?php
class Box implements ArrayAccess {
    public array $d = [];
    public int $sets = 0;
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o] ?? null; }
    public function offsetSet(mixed $o, mixed $v): void { $this->sets++; if ($o === null) { $this->d[] = $v; } else { $this->d[$o] = $v; } }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
class Ret implements ArrayAccess {
    public function offsetExists(mixed $o): bool { return true; }
    public function offsetGet(mixed $o): mixed { return 'got'; }
    public function offsetSet(mixed $o, mixed $v): void { }
    public function offsetUnset(mixed $o): void { }
}
function side(string $s, int $v) { echo "side $s\n"; return $v; }
$b = new Box();
var_dump($b[0] = 2.5);
var_dump(($b[1] = 3) + 1);
var_dump($b['k'] = 'str');
$v = 7;
var_dump($b[2] = $v);
var_dump($b[] = true);
$x = $b[5] = 9;
var_dump($x, $b->d[5]);
$a = $b[6] = $b[7] = 11;
var_dump($a, $b->d[6], $b->d[7]);
var_dump($b[side('k', 1)] = side('v', 42));
var_dump($b->sets);
$r = new Ret();
var_dump($r['a'] = 1.5);
var_dump($r[] = 4);
$s = new SplFixedArray(3);
var_dump($s[0] = 2.5);
var_dump($s[1] = 5);
$y = $s[2] = 8;
var_dump($y, $s[2]);
$b['c'] = 'x';
$b['c'] .= 'y';
var_dump($b['c']);
function f(Box $bx, int $i) { return $bx[$i] = $i * 2; }
var_dump(f($b, 21));
var_dump(array_map(fn($i) => $b[$i + 100] = $i, [1, 2]));
