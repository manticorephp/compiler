<?php
// A by-ref argument through an ABSTRACT method (no body, no signature of
// its own) must still pass the slot address to the override that runs.
abstract class Base { abstract public function step(int &$x): void; }
final class Plain extends Base { public function step(int &$x): void { $x = $x + 10; } }
final class Plain2 extends Base { public function step(int &$x): void { $x = $x + 20; } }
/** @param Base[] $bs */
function run_all(array $bs): void {
    foreach ($bs as $b) { $v = 3; $b->step($v); var_dump($v); }
}
run_all([new Plain(), new Plain2()]);
