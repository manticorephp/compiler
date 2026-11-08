<?php
// Erased invokes whose arguments are plain values run through one shared helper:
// function names, closures, invokable objects, callable arrays, the trace line of
// each call site.
function twice(int $x): int { return $x * 2; }
class Inv { public function __invoke(mixed $v): string { return 'inv:' . var_export($v, true); } }
class Tool {
    public function m(mixed $v): string { return 'm:' . var_export($v, true); }
    public static function s(mixed $v): string { return 's:' . var_export($v, true); }
}
function boom(mixed $v): never { throw new \RuntimeException('boom ' . var_export($v, true)); }

function run(mixed $f): void {
    echo var_export($f(null), true), ' ', var_export($f(true), true), ' ', var_export($f(3), true), "\n";
}
run('twice' === 'x' ? 'strlen' : fn ($v) => [$v]);
run(new Inv());
run([new Tool(), 'm']);
run(['Tool', 's']);
echo twice(4), ' ', (static fn (): mixed => ('twice')(21))(), "\n";

function lines(mixed $f): void {
    try { $f('a'); } catch (\RuntimeException $e) { echo $e->getMessage(), ' at ', $e->getTrace()[1]['line'] ?? '?', "\n"; }
    try { $f('b'); } catch (\RuntimeException $e) { echo $e->getMessage(), ' at ', $e->getTrace()[1]['line'] ?? '?', "\n"; }
}
lines('boom');


// A plain cell variable goes over as the caller's slot: a by-reference closure writes it.
function viaSlot(mixed $f, mixed $v): mixed { $w = $v; $f($w); return $w; }
var_export(viaSlot(function (&$x) { $x = 'set'; }, 1)); echo "\n";
var_export(viaSlot(function ($x) { $x = 'lost'; }, 2)); echo "\n";
var_export(viaSlot(function (&$x) { $x[] = 'pushed'; }, [0])); echo "\n";
