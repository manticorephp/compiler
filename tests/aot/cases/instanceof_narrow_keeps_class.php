<?php
interface Aware {}
interface Named { public function label(): string; }
class MagicBox { public array $seen = []; public function __set($n, $v) { $this->seen[] = "$n=$v"; } }
abstract class Base implements Named {
    public string $name = '';
    public ?string $cfg = null;
    public function __construct() {
        $this->name = static::class;
        if ($this instanceof Aware) { $this->cfg = 'ws'; }
        if ($this instanceof Named) { $this->name .= '!'; }
    }
    public function label(): string { return $this->name . ':' . var_export($this->cfg, true); }
}
final class A extends Base implements Aware {}
final class B extends Base {}
final class C extends Base implements Aware { public int $extra = 1; }
function setCfg(Aware $x): void { $x->cfg = 'direct'; }
foreach ([new A(), new B(), new C()] as $o) { echo $o->label(), "\n"; }
$a = new A();
setCfg($a);
echo $a->label(), "\n";
$m = new MagicBox();
$m->zzz = 1;
echo implode(',', $m->seen), "\n";
interface HasP {}
final class PX implements HasP { public int $a = 7; public int $p = 0; }
final class PY implements HasP { public int $p = 0; }
function setP(HasP $o, int $v): void { $o->p = $v; }
$px = new PX(); $py = new PY();
setP($px, 3); setP($py, 4);
echo $px->a, ' ', $px->p, ' ', $py->p, "\n";
