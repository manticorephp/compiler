<?php
// `is_a` / `is_subclass_of` over a subject whose class is NOT a compile-time
// fact: an interface-typed param (interfaces have no ClassDef at all, so the
// name-based fold bailed) and an erased `mixed` one. Both folded to FALSE.
// `$x instanceof $cls` lowers to is_a, so dynamic instanceof rode the same hole.

interface Base {}
interface Wrap extends Base {}
class Impl implements Wrap { public function __construct(public int $n = 1) {} }
class Sub extends Impl {}
class Other {}

function viaIface(Base $b): string
{
    return (is_a($b, 'Impl') ? 'y' : 'n')
         . (is_a($b, 'Base') ? 'y' : 'n')
         . (is_a($b, 'Wrap') ? 'y' : 'n')
         . (is_a($b, 'Other') ? 'y' : 'n');
}

function viaMixed(mixed $m): string
{
    return (is_a($m, 'Impl') ? 'y' : 'n') . (is_subclass_of($m, 'Impl') ? 'y' : 'n');
}

$i = new Impl();
$s = new Sub();
echo viaIface($i), "\n";
echo viaIface($s), "\n";
echo viaMixed($i), "\n";
echo viaMixed($s), "\n";
// A non-object subject is an instance of nothing — and reading a class id out
// of it would be a wild load, so it keeps the constant fold.
echo viaMixed('str'), "\n";
echo viaMixed(42), "\n";

// A statically-typed subject still folds at compile time.
echo is_a($i, 'Impl') ? "lit-y\n" : "lit-n\n";
echo is_subclass_of($s, 'Impl') ? "sub-y\n" : "sub-n\n";
echo is_subclass_of($i, 'Impl') ? "self-y\n" : "self-n\n";

// Dynamic instanceof, both operands runtime.
$cls = 'Impl';
echo ($i instanceof $cls) ? "dyn-y\n" : "dyn-n\n";
$arr = [$i, $s, new Other()];
foreach ($arr as $e) {
    echo is_a($e, 'Base') ? '1' : '0';
}
echo "\n";
