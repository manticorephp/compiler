<?php

// REIFIED class generics: `/** @var Box<float> $b */ $b = new Box();` builds a
// real class with float properties and a float-typed body — not one erased body
// whose `T` values ride as tagged cells. Same program, measured on the emitted
// IR: the erased form has 6 boxing ops, this one has 0 outside the thunks.
//
// A specialization is a SUBCLASS of its origin, so `instanceof` / `catch` /
// dispatch (compile-time class-id sets, walked over the parent chain) already
// see it, and it reports its origin's name to `get_class` / `::class`.
//
// The load-bearing case is the ERASED BOUNDARY: a bare `Box $b` has no binding,
// and `Box<float>::get` returns a raw double while `Box<string>::get` returns a
// raw pointer — both i64, indistinguishable to that caller. Each specialized
// method therefore gets a second entry (the erased thunk) that boxes its result
// and unboxes its args, and the dispatch switch calls THAT when the receiver is
// erased. 2^50 below is the probe: a raw int of that size read as a tagged cell
// comes back 0.

/** @template T */
class Box
{
    /** @var T[] */
    private array $items = [];

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }

    public function count(): int { return \count($this->items); }
}

/** @template K @template V */
class Pair
{
    /** @var K */
    private $k;
    /** @var V */
    private $v;

    /** @param K $k @param V $v */
    public function __construct($k, $v) { $this->k = $k; $this->v = $v; }

    /** @return K */
    public function key() { return $this->k; }

    /** @return V */
    public function val() { return $this->v; }
}

final class Tag
{
    public function __construct(public readonly string $name) {}
}

// A type parameter in a PUBLIC property cannot be reified — a field read has no
// thunk to hang the representation change on — so this one stays erased. It must
// still be correct.
/** @template T */
class Open
{
    /** @var T */
    public $value;

    /** @param T $v */
    public function set($v): void { $this->value = $v; }
}

// The erased boundary: no binding here, in either direction.
function firstFloat(Box $b): float { return $b->get(0); }
function firstInt(Box $b): int { return $b->get(0); }
function firstStr(Box $b): string { return $b->get(0); }
function howMany(Box $b): int { return $b->count(); }
function put(Box $b, $v): void { $b->add($v); }

// A binding written next to a real hint: the docblock refines the same class.
/** @param Box<float> $b */
function sum(Box $b): float { return $b->get(0) + $b->get(1); }

/** @var Box<float> $f */
$f = new Box();
$f->add(1.5);
$f->add(2.25);
echo $f->get(0) + $f->get(1), "\n";
echo $f->get(1) * 2.0, "\n";
echo sum($f), "\n";
echo firstFloat($f), "\n";

/** @var Box<int> $i */
$i = new Box();
$i->add(1125899906842624);   // 2^50 — too big to survive as an untagged cell
$i->add(8);
echo firstInt($i), "\n";
echo $i->get(0) + $i->get(1), "\n";

/** @var Box<string> $s */
$s = new Box();
$s->add('ab');
$s->add('cd');
echo $s->get(0) . $s->get(1), "\n";
echo firstStr($s), "\n";
echo strlen($s->get(1)), "\n";

/** @var Box<Tag> $t */
$t = new Box();
$t->add(new Tag('alpha'));
echo $t->get(0)->name, "\n";

// A cell argument crossing into a specialized raw param.
put($f, 8.5);
echo $f->get(2), "\n";

/** @var Pair<string, float> $p */
$p = new Pair('pi', 3.5);
echo $p->key(), '=', $p->val(), "\n";

// An object built with NO binding is erased, and stays erased even where a bound
// slot names it — its storage holds tagged cells, not raw ints.
$plain = new Box();
$plain->add(1125899906842624);
echo firstInt($plain), "\n";

/** @var Open<float> $o */
$o = new Open();
$o->set(6.25);
echo $o->value + 0.25, "\n";

echo howMany($f), howMany($i), howMany($s), howMany($t), "\n";

// PHP identity: a specialization answers as its origin.
echo get_class($f), ' ', $f::class, ' ', get_class($t), "\n";
var_dump($f instanceof Box);
var_dump($plain instanceof Box);

try {
    throw new RuntimeException('boom');
} catch (RuntimeException $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
