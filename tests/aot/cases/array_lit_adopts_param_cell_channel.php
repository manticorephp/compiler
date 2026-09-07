<?php

// A HOMOGENEOUS array literal is typed from its own values, so it kept a
// concrete element channel even when the callee declared `array<K, mixed>` —
// cell slots holding raw words, which every read decoded as an address. One
// int in the literal used to be the difference between a string and a number.

class Bag implements ArrayAccess
{
    /** @param array<string, mixed> $d */
    public function __construct(private array $d) {}
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return isset($this->d[$o]) ? $this->d[$o] : null; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}

/** @param array<string, mixed> $m */
function pick(array $m, string $k): mixed { return $m[$k]; }

class Holder
{
    /** @var array<string, mixed> */
    private array $m = [];
    /** @param array<string, mixed> $m */
    public function put(array $m): void { $this->m = $m; }
    public function get(string $k): mixed { return $this->m[$k]; }
}

// The erased site must answer for BOTH shapes — an array and an ArrayAccess.
function byLit(mixed $x): mixed { return $x['name']; }

var_dump(byLit(['name' => 'ann']));
var_dump(byLit(new Bag(['name' => 'bob'])));

// A plain function parameter, homogeneous literal.
var_dump(pick(['a' => 'one'], 'a'));

// A method parameter, homogeneous literal.
$h = new Holder();
$h->put(['k' => 'val']);
var_dump($h->get('k'));

// Heterogeneous literals were already correct — they must stay correct.
var_dump(pick(['a' => 'one', 'b' => 2], 'a'));
var_dump(byLit(new Bag(['name' => 'bob', 'n' => 1])));
