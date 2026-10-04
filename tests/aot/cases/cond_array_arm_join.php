<?php
// A conditional whose array arms hold different element kinds (a mixed map, an
// object map) yields ONE representation: every element read through the joined
// value answers what php answers, whichever arm ran.
final class V { public function __construct(public string $s) {} }
final class S {
    /** @var array<string, mixed> */
    public array $m = ['a' => 'str', 'n' => 7];
    /** @return array<string, V> */
    public function objs(): array { return ['a' => new V('obj'), 'n' => new V('seven')]; }
    /** @return array<string, int> */
    public function ints(): array { return ['a' => 1, 'n' => 2]; }
}
function show(mixed $v): string { return is_object($v) ? 'V(' . $v->s . ')' : gettype($v) . '(' . $v . ')'; }
$s = new S();
foreach ([true, false] as $c) {
    $x = $c ? $s->m : $s->objs();
    echo show($x['a']), ' ', show($x['n']), "\n";
    $y = $c ? $s->ints() : $s->m;
    echo show($y['a']), ' ', show($y['n']), "\n";
    $z = match ($c) { true => $s->objs(), false => $s->ints() };
    foreach ($z as $k => $v) { echo $k, '=', show($v), ' '; }
    echo "\n";
    $w = $c ? null : $s->objs();
    $u = $w ?? $s->ints();
    echo show($u['a']), "\n";
}
