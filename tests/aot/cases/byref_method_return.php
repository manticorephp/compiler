<?php
// Return-by-reference from a method: the callee yields the slot address, a
// value-context call derefs it, and `$r = &$obj->m()` binds an alias.
class Box {
    public int $v = 0;
    private array $data = [10, 20, 30];
    function &ref() { return $this->v; }
    function &elem(int $i) { return $this->data[$i]; }
    function sum() { $s = 0; foreach ($this->data as $x) { $s += $x; } return $s; }
}

$b = new Box();

// bind an alias to a property, mutate through it
$r = &$b->ref();
$r = 100;
$r++;
echo $b->v, "\n";              // 101

// value context derefs to the value
echo $b->ref(), "\n";          // 101

// alias to an array element returned by reference
$e = &$b->elem(1);
$e = 999;
echo $b->sum(), "\n";          // 10 + 999 + 30 = 1039

// virtual dispatch: an inherited by-ref method still aliases
class Base { protected int $n = 1; function &get() { return $this->n; } }
class Derived extends Base {}
$d = new Derived();
$g = &$d->get();
$g = 55;
echo $d->get(), "\n";          // 55

// a normal (by-value) method is unaffected
class Plain { function add(int $a, int $b): int { return $a + $b; } }
echo (new Plain())->add(3, 4), "\n";   // 7
