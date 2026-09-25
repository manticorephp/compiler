<?php
// An invokable OBJECT reached through a cell (a `mixed` return, a capture) is
// called via __invoke, not as a closure struct; and a by-ref foreach variable
// over CELL elements boxes every store (php-cs-fixer wraps invokable allowed
// values in `$v = static fn ($x) => $v($x)`).
final class Inv { public function __invoke($v): string { return 'inv:' . $v; } }
final class Subset
{
    public function __construct(private array $allowed) {}
    public function __invoke($values): bool
    {
        foreach ($values as $v) { if (!\in_array($v, $this->allowed, true)) { return false; } }
        return true;
    }
}
function id(mixed $x): mixed { return $x; }

$c = id(new Inv());
var_dump($c('a'));
$f = static fn ($v) => $c($v);
var_dump($f('b'));
foreach ([new Inv()] as $x) { var_dump($x('c')); }

$s = id(new Subset(['const', 'property']));
var_dump($s(['const']), $s(['zz']));
$g = static fn ($values) => $s($values);
var_dump($g(['property']));

function wrap(array $a): array
{
    foreach ($a as &$x) {
        if (\is_object($x)) { $x = static fn ($v) => $x($v); }
    }
    return $a;
}
$w = wrap([new Inv(), 'k']);
var_dump(($w[0])('q'), $w[1]);
