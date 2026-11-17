<?php
// print_r and var_export of objects through one generic walk over the (array) view: visibility keys, inherited privates, uninitialized and dynamic properties, __debugInfo, hooks, enums, closures
namespace App\Model;

class Base { private int $secret = 1; protected string $kind = 'base'; public ?Base $next = null; }
class Child extends Base { private int $secret = 2; public array $tags = ['a', 'b']; public int $late; }
#[\AllowDynamicProperties]
class Dyn { public int $a = 1; }
class Dbg { private int $hidden = 5; public function __debugInfo(): array { return ['shown' => 7]; } }
class Hooked {
    public string $first = 'Ada';
    public string $full { get => $this->first . ' L'; }
    public int $stored = 3 { set => $value * 2; }
}
final class Ro { public function __construct(public readonly int $id, protected readonly string $name = 'n') {} }
enum Suit: string { case Hearts = 'h'; }
enum Plain { case One; }

$c = new Child();
$c->next = new Base();
$d = new Dyn();
$d->extra = 'x';
$d->{'7'} = 'seven';
$s = new \stdClass();
$s->p = 1;
$s->q = [1, 2];
$all = [$c, $d, new Dbg(), new Hooked(), new Ro(9), Suit::Hearts, Plain::One, $s];
foreach ($all as $o) {
    print_r($o);
    echo "\n";
}
foreach ($all as $o) {
    var_export($o);
    echo "\n";
}
echo print_r([$c, 'k' => $s], true);
var_export(['x' => $d, 'y' => [Suit::Hearts]]);
echo "\n";
