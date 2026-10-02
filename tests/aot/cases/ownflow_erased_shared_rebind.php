<?php
// Regression guard (passes before the fix too). A local that owns an
// erased-array result, is re-bound to a concrete array
// and handed to a callee that may keep it (an object-producing closure): its
// release stays buffer-only, so the holder's elements outlive the local and
// nothing the holder still reads is freed.
final class D
{
    public static bool $on = true;
    public function __construct(public string $n) {}
    public function __destruct() { if (self::$on) { echo "~", $this->n, "\n"; } }
}
final class H { public function __construct(public array $items) {} }
function erase(array $a): array { return $a; }
function run(bool $c): H
{
    $mk = fn(array $x): H => new H($x);
    $r = erase(['k' => new D('e')]);
    if ($c) { $r = ['k' => new D('lit')]; }
    $h = $mk($r);
    unset($r);
    echo "held ", $h->items['k']->n, "\n";
    return $h;
}
$h1 = run(true);
$h2 = run(false);
echo "got ", $h1->items['k']->n, $h2->items['k']->n, "\n";
// The holders' own release of the kept elements is not what this test is
// about (an erased array property's drop, Task 11): silence the teardown.
D::$on = false;
unset($h1, $h2);
echo "done\n";
