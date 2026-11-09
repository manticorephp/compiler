<?php
// A call on an erased receiver where some classes declare the method and others answer it
// through __call: the __call arm runs through a shared helper, with plain and fresh arguments.
class Real { public function greet(string $who, int $n = 1): string { return str_repeat("hi $who ", $n); } }
class Proxy { public array $seen = []; public function __call(string $m, array $a): string { $this->seen[] = $m; return $m . '(' . implode(',', $a) . ')'; } }
class Other { public function __call(string $m, array $a): mixed { return count($a); } }

function pick(int $i): object { return match ($i % 3) { 0 => new Real(), 1 => new Proxy(), default => new Other() }; }
function suffix(int $i): string { return 'x' . $i; }

for ($i = 0; $i < 6; $i++) {
    $o = pick($i);
    echo get_class($o), ': ', var_export($o->greet('w' . $i, 2), true), ' | ', var_export($o->greet(suffix($i)), true), "\n";
}
$p = new Proxy();
$q = pick(1);
echo $q->greet(str_repeat('a', 3)), ' ', json_encode($q->seen), "\n";
$before = memory_get_usage();
for ($i = 0; $i < 20000; $i++) { $o = pick($i); $o->greet(suffix($i), 1); }
echo memory_get_usage() - $before < 200000 ? "flat\n" : "grows\n";
