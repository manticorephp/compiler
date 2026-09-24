<?php
final class Upper
{
    public function __invoke(mixed $v): string { return strtoupper((string)$v); }
}

function twice(mixed $v): string { return $v . $v; }

function apply($cb, $v)
{
    return $cb($v);
}

function viaVar(array $cbs, mixed $v): void
{
    foreach ($cbs as $cb) {
        echo apply($cb, $v), "\n";
        $r = $cb($v);
        echo $r, "\n";
    }
}

viaVar(['twice', new Upper(), fn($x) => "<$x>", 'strrev'], 'ab');
var_dump(apply('is_float', 1.5), apply('is_string', 2), apply('is_null', null), apply(new Upper(), 'q'));
$ap = static function ($cb) { return static fn($m) => $cb($m); };
foreach (['is_null', 'is_array', 'is_string', new Upper(), 'twice'] as $c) {
    var_dump($ap($c)(null), $ap($c)("x"));
}
