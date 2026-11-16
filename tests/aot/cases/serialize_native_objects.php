<?php
// serialize() of objects through the native writer: keys, counts, numbering, bail paths.
enum Suit { case Hearts; case Spades; }
enum Code: string { case A = 'a'; case B = 'b'; }

class Base { public int $pub = 1; protected string $prot = 'p'; private string $priv = 'base'; private int $shadow = 10; }
class Child extends Base { private int $shadow = 20; public ?string $nul = null; public float $f = 0.5; }
class Lazy { public int $a; public ?string $b; public string $c = 'set'; protected array $d; }
#[AllowDynamicProperties]
class Bag { public int $x = 1; private string $y = 'y'; }
class Node { public ?Node $next = null; public function __construct(public string $name) {} }
final class Ro { public function __construct(public readonly int $id, public readonly string $tag) {} }
class Hooked {
    public string $name = 'n' { set => strtoupper($value); }
    public string $virt { get => 'v'; }
}
class Custom { public int $a = 1; private int $b = 2; public function __serialize(): array { return ['a' => $this->a, 7 => 'seven', 'obj' => new Node('in')]; } public function __unserialize(array $d): void { $this->a = $d['a']; } }
class Holder { public Suit $suit = Suit::Spades; public Code $code = Code::B; public array $list = []; }
class Floats { public float $a = 1.0; public float $b = -0.0; public float $c = 0.1; public float $d = 1e100; public float $e = -1.5e-7; public float $i = INF; public float $n = -INF; }

function show(string $label, mixed $v): void {
    $s = serialize($v);
    echo $label, ': ', str_replace("\0", '~', $s), "\n";
    echo '  rt: ', serialize(unserialize($s)) === $s ? 'ok' : 'DIFF', "\n";
}

show('child', new Child());
$l = new Lazy(); $l->b = null;
show('lazy', $l);
show('lazy-empty', new Lazy());
$b = new Bag(); $b->extra = [1, 2]; $b->{'5'} = 'five'; $b->z = new Node('z');
show('bag', $b);
$n1 = new Node('a'); $n2 = new Node('b'); $n1->next = $n2;
show('same', [$n1, $n2, $n1, 3, $n2]);
show('deep', [[1, [$n2]], ['k' => [$n1, 'x' => [$n2, $n1]]], $n1]);
$cyc = new Node('cyc'); $cyc->next = $cyc;
show('cycle', $cyc);
show('enums', [Suit::Hearts, Suit::Hearts, Code::A, new Holder()]);
$h = new Holder(); $h->list = [Suit::Hearts, $n1, $n1];
show('holder', $h);
$o = new stdClass(); $o->a = 1; $o->{'7'} = 'seven'; $o->inner = new stdClass();
show('std', $o);
show('cast', (object)['x', 'y', 'k' => true]);
show('ro', new Ro(5, 'r'));
$hk = new Hooked(); $hk->name = 'abc';
show('hooked', $hk);
show('custom', [new Custom(), new Custom()]);
show('mixed-custom', [$n1, new Custom(), $n1]);
show('floats', new Floats());
show('scalars', [null, true, false, 0, -7, PHP_INT_MAX, PHP_INT_MIN, 1.5, NAN, '', "a\0b", 'ü']);
try {
    serialize([1, [fn() => 1]]);
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $x = new Node('c'); $x->next = null;
    serialize(['a' => $x, 'f' => function () { return 2; }]);
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$u = unserialize(serialize(new Child()));
var_dump($u instanceof Child, $u->f, $u->nul);
$p = unserialize(serialize([$n1, $n1]));
var_dump($p[0] === $p[1], $p[0]->next->name);
