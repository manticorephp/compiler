<?php
#[\Deprecated("x")]
function oldFn(): int { return 1; }
function newFn(): int { return 2; }
class C {
  #[\Deprecated] public function oldM(): void {}
  public function newM(): void {}
}
var_dump((new ReflectionFunction('oldFn'))->isDeprecated());
var_dump((new ReflectionFunction('newFn'))->isDeprecated());
var_dump((new ReflectionMethod('C', 'oldM'))->isDeprecated());
var_dump((new ReflectionMethod('C', 'newM'))->isDeprecated());
