<?php
namespace App\Model;
final class P {
    public int $n = 0;
    public function __serialize(): array { return [1, 2, $this->n]; }
    public function __unserialize(array $d): void { $this->n = \count($d); }
}
final class Q { public int $x = 5; }
$p = new P(); $p->n = 7;
$s = serialize($p); echo $s, "\n";
$r = unserialize($s); echo get_class($r), ' ', $r->n, "\n";
$t = serialize(new Q()); echo $t, "\n";
$u = unserialize($t); echo get_class($u), ' ', $u->x, "\n";
