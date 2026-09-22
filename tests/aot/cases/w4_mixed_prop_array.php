<?php
// A `mixed` / unhinted property is ONE storage with ONE repr: a cell. An array
// stored into it is boxed FLAT (the same buffer under a tag), whatever else the
// program does with the slot — a key buffer, an element-written bag, a
// whole-read value container all agree. Before, the store's repr was decided
// by a usage census (raw when only arrays were ever stored) and the READ by
// the static type (cell), so `$o->u = [5]; var_dump($o->u)` printed a denormal
// and an SPL-style `$this->k = $ks` read back as float(2.16E-314).
class U { public $u; public mixed $m; public mixed $n = null; }
$o = new U();
$o->u = [5, 6];             var_dump($o->u);
$a = [3, 4];  $o->u = $a;   var_dump($o->u, count($o->u), $o->u[1]);
$o->m = ['k' => 'v', 2 => [1, 2]]; var_dump($o->m, $o->m['k'], $o->m[2][1], is_array($o->m[2]));
$o->n = [];  var_dump(is_array($o->n) && count($o->n) === 0, $o->n === null);

// The SPL key-buffer shape: a vec of keys stored whole, read whole and by index.
class KB {
    private mixed $s; private mixed $k; private int $i = 0;
    public function __construct(mixed $arr) { $this->s = $arr; $ks = []; foreach ($this->s as $k => $v) { $ks[] = $k; } $this->k = $ks; }
    public function keys(): mixed { return $this->k; }
    public function at(int $i): mixed { return $this->s[$this->k[$i]]; }
    public function walk(): string { $out = ''; foreach ($this->k as $k) { $out .= $k . '=' . $this->s[$k] . ' '; } return $out; }
}
$kb = new KB(['a' => 1, 7 => 'x', 'z' => null]);
var_dump($kb->keys(), $kb->at(1), $kb->walk());

// The element-written bag: append, keyed write, nested write, unset, by-ref.
class Bag {
    public mixed $v = [];
    public function add(mixed $x): void { $this->v[] = $x; }
    public function set(string $k, mixed $x): void { $this->v[$k] = $x; }
    public function nest(string $k, mixed $x): void { $this->v[$k][] = $x; }
    public function drop(string $k): void { unset($this->v[$k]); }
    public function all(): array { return $this->v; }
    public function raw(): mixed { return $this->v; }
    public function sorted(): array { $c = $this->v; sort($c); return $c; }
    public function sortInPlace(): void { sort($this->v); }
}
$b = new Bag();
$b->add(3); $b->add(1); $b->set('s', 'str'); $b->nest('n', 'a'); $b->nest('n', 'b');
var_dump($b->all(), $b->raw(), count($b->v), isset($b->v['s']), isset($b->v['zz']));
$b->drop('s'); var_dump(array_keys($b->v));
$b2 = new Bag(); $b2->add(3); $b2->add(1); $b2->add(2);
var_dump($b2->sorted()); $b2->sortInPlace(); var_dump($b2->v);
foreach ($b->v as $k => $x) { echo $k, ':', is_array($x) ? implode('', $x) : $x, "\n"; }

// A whole store of another object's array and a scalar into the same slot.
class Mix { public mixed $val; }
$m = new Mix(); $m->val = 5; var_dump($m->val); $m->val = [1, 2]; var_dump($m->val); $m->val = 'z'; var_dump($m->val);
$m->val = $b->v; var_dump(count($m->val));
// Inheritance: the slot is declared once.
class Base { public mixed $data = []; public function push(mixed $x): void { $this->data[] = $x; } }
class Derived extends Base { public function total(): int { $n = 0; foreach ($this->data as $x) { $n += $x; } return $n; } }
$d = new Derived(); $d->push(10); $d->push(20); var_dump($d->total(), $d->data);
// Passed on and json'd.
function takes(array $a): int { return count($a); }
var_dump(takes($o->u), json_encode($o->m), json_encode($b->v));
