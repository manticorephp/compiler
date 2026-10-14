<?php
class P { public int $x = 1; function nul(): ?P { return null; } static function snul(): ?P { return null; } function some(): ?P { return new P; } function z(): mixed { return 0.0; } }
function d($v): void { echo is_object($v) ? get_class($v) : var_export($v, true), "\n"; }
function show(callable $f): void { d($f()); }
function viaCuf(callable $f): void { d(call_user_func($f)); }
function mixed0(): mixed { return 0.0; }
function nullObj(): ?P { return null; }
function someObj(): ?P { return new P; }
$p = new P;
$cbs = [fn() => json_decode('0.0'), fn(): ?P => null, fn(): mixed => 0.0, fn(): ?P => new P, fn() => current([0.0]), fn(): float => 0.0, fn(): int => 0,
    $p->nul(...), P::snul(...), $p->some(...), $p->z(...), mixed0(...), nullObj(...), someObj(...), Closure::fromCallable([$p, 'nul'])];
foreach ($cbs as $cb) { show($cb); viaCuf($cb); d($cb()); }
foreach (array_map(fn($f) => $f(), $cbs) as $r) { d($r); }
show([$p, 'nul']); show([$p, 'z']); show('P::snul');
show('mixed0'); show('nullObj'); viaCuf('mixed0'); viaCuf('nullObj'); show('someObj');
