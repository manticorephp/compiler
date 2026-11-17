<?php
// var_dump of objects through one generic walk over the (array) view, with an arm only for a class whose slot may be uninitialized; var_export of a subclass of a class with a virtual hooked property
namespace App\Dump;

class Base { private int $secret = 1; protected string $kind = 'base'; public ?Base $next = null; }
class Child extends Base { private int $secret = 2; public array $tags = ['a', 'b']; }
class Partial { public int $set = 1; public int $late; public ?string $maybe = null; }
#[\AllowDynamicProperties]
class Dyn { public int $a = 1; }
class Dbg { private int $hidden = 5; public function __debugInfo(): array { return ['shown' => 7, 3 => 'three']; } }
class Hooked {
    public string $first = 'Ada';
    public string $full { get => $this->first . ' L'; }
}
class HookedChild extends Hooked { public int $extra = 4; }
final class Ro { public function __construct(public readonly int $id, protected readonly string $name = 'n') {} }
enum Suit: string { case Hearts = 'h'; }

$c = new Child();
$c->next = new Base();
$d = new Dyn();
$d->extra = 'x';
$d->{'7'} = 'seven';
$s = new \stdClass();
$s->p = 1;
$p = new Partial();
foreach ([$c, new Partial(), $d, new Dbg(), new Hooked(), new HookedChild(), new Ro(9), Suit::Hearts, $s] as $o) {
    var_dump($o);
}
$p->late = 5;
var_dump($p);
var_dump([$c, 'k' => $s]);
var_export(new HookedChild());
echo "\n";
