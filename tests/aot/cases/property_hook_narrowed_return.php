<?php
// A get hook whose body answers narrower than the property's type (an int from an int|string hook, a float from a
// float|string one) reads as the value through an exact, a union and an erased receiver.
final class St { public int $n = 3; }
class R
{
    public int|string $num { get => $this->st->n; set { throw new Error('ro'); } }
    public float|string $avg { get => $this->st->n / 2; }
    public ?array $lens { get => [$this->st->n]; }
    private St $st;
    public function __construct() { $this->st = new St(); }
}
class S extends R {}
function mk(bool $b): R|bool { return $b ? new S() : false; }
function viaMixed(mixed $o): array { return [$o->num, $o->avg, $o->lens]; }
$r = new R();
echo json_encode([$r->num, $r->avg, $r->lens]), "\n";
var_dump($r->num, $r->avg);
$u = mk(true);
echo json_encode([$u->num, $u->avg, $u->lens]), "\n";
$f = fn() => $u->num;
var_dump($f());
echo json_encode(viaMixed($r)), "\n";
