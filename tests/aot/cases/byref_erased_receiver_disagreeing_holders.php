<?php
// An erased receiver dispatching to holders of a method that disagree on keeping, taking or ignoring a by-ref argument.
class Ot { public function initialize(object $o): void { echo "ot:", get_class($o), "\n"; } }
class Oz { public function initialize(): void { echo "oz\n"; } }
class AB { public array $at = []; public function initialize(array &$a): void { $this->at = &$a; } }
class FB { public array $fl = []; public function initialize(array &$a): void { $a['seen'] = 1; } }
class VV { public function initialize(array $a): void { $a['no'] = 1; echo "vv:", count($a), "\n"; } }
function bags(): array { return [new AB, new FB, new VV]; }
function run(array &$sess): void {
  foreach (bags() as $i => $bag) { $key = "k$i"; $sess[$key] = []; $bag->initialize($sess[$key]); if ($bag instanceof AB) { $bag->at['x'] = 1; } }
}
$s = []; run($s); echo json_encode($s), "\n";
foreach ([new Ot, new Oz] as $o) { if ($o instanceof Ot) { $o->initialize(new stdClass); } else { $o->initialize(); } }
function any(mixed $o, array &$a) { $o->initialize($a); }
$z = []; any(new AB, $z); any(new FB, $z); any(new VV, $z); echo json_encode($z), "\n";
