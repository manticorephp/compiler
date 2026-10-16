<?php
// is_callable([$obj, $name]) with a run-time method name or an erased pair asks the runtime tables.
trait T { public function loadRoutes(int $x): int { return $x + 1; } }
class Base { public function baseM(): int { return 1; } public static function make(): static { return new static(); } }
class K extends Base { use T; public function own(): int { return 2; } }
class M { public function __call(string $n, array $a): mixed { return $n; } }
class Inv { public function __invoke(): int { return 3; } }
function ids(mixed $v): mixed { return $v; }
$k = ids(new K());
foreach (['loadRoutes', 'own', 'baseM', 'nope'] as $m) {
    $m = ids($m);
    var_dump(is_callable([$k, $m]));
}
var_dump(is_callable(ids([new M(), 'anything'])));
var_dump(is_callable(ids(['K', 'make'])), is_callable(ids(['K', 'nope'])));
var_dump(is_callable(ids(new Inv())), is_callable(ids(new K())), is_callable(ids(fn () => 1)));
var_dump(is_callable(ids([1, 2])), is_callable(ids(['a' => $k, 'b' => 'own'])), is_callable(ids(5)));
