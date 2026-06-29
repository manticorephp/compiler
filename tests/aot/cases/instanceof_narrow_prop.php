<?php
class Dog { public string $name = "Rex"; public function speak(): string { return "woof"; } }
class Cat { public string $name = "Tom"; public function speak(): string { return "meow"; } }
function describe(mixed $a): void {
  if ($a instanceof Dog) { echo $a->name, " says ", $a->speak(), "\n"; }
  elseif ($a instanceof Cat) { echo $a->name, " says ", $a->speak(), "\n"; }
  else { echo "unknown\n"; }
}
describe(new Dog());
describe(new Cat());
describe(42);
