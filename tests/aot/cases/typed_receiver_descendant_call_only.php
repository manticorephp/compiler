<?php
class Base { public function setTs($t): static { return $this; } }
class Dt extends Base {
    private array $log = [];
    public function __call($m, $a) { $this->log[] = $m; return $this; }
    public function n() { return count($this->log); }
}
class Plain extends Base {}
function run(Base $b) { return $b->setTs(1); }
$d = run(new Dt);
echo get_class($d), "\n";
// typed as Base, the method exists only through a descendant's __call
function viaBase(Base $b) { return $b->setMicro(5)->setMicro(6); }
echo viaBase(new Dt)->n(), "\n";
