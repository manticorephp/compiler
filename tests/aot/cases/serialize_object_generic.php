<?php
// serialize of objects through one generic walk over the (array) view: mangled visibility keys, shadowed privates, uninitialized and dynamic properties, __serialize, enums, back-references, hooks, and the unserialize round trip
namespace App\Ser;

class Base { private int $secret = 1; protected string $kind = 'base'; public ?Base $next = null; }
class Child extends Base { private int $secret = 2; public array $tags = ['a', 'b']; }
class Partial { public int $set = 1; public int $late; public ?string $maybe = null; }
#[\AllowDynamicProperties]
class Dyn { public int $a = 1; }
class Custom { private int $x = 3; public function __serialize(): array { return ['x' => $this->x, 5 => 'five']; }
    public function __unserialize(array $d): void { $this->x = $d['x']; } }
class Hooked { public string $first = 'Ada'; public string $full { get => $this->first . ' L'; } public int $stored = 3 { set => $value * 2; } }
final class Ro { public function __construct(public readonly int $id, protected readonly string $name = 'n') {} }
enum Suit: string { case Hearts = 'h'; }

$c = new Child();
$shared = new Base();
$c->next = $shared;
$d = new Dyn();
$d->extra = 'x';
$d->{'7'} = 'seven';
$s = new \stdClass();
$s->p = 1;
$items = [$c, new Partial(), $d, new Custom(), new Hooked(), new Ro(9), Suit::Hearts, $s, $shared, [$shared, $c]];
foreach ($items as $o) {
    $ser = serialize($o);
    echo str_replace("\0", '~', $ser), "\n";
    $back = unserialize($ser);
    echo serialize($back) === $ser ? "round trip ok\n" : "round trip DIFF\n";
}
echo str_replace("\0", '~', serialize($items)), "\n";
