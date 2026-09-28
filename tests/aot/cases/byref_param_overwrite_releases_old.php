<?php
// A whole store through a by-ref parameter replaces the value the caller's
// storage owns, and gave nothing back: every prelude key sort's `$arr = $new`
// leaked the caller's array, and a matches variable reused across
// `Preg::match(…, $m)` calls leaked each previous array. A borrowed caller
// variable (a by-value parameter handed on by reference) takes a reference
// first, so the callee's release never reaches the caller's caller.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function mk1(string $s): array { $r = []; $r[] = new Tok($s); return $r; }
function replace(array &$a, string $n): void { $a = mk1($n); }
final class Box { /** @var array<int, Tok> */ public array $items = []; }
function local(): void {
    $s = mk1('first');
    replace($s, 'second');
    echo "replaced\n";
    replace($s, 'third');
    echo "replaced again\n";
}
local();
echo "after local\n";
function sorts(): void {
    $s = []; $s['b'] = mk1('kb'); $s['a'] = mk1('ka'); ksort($s); echo implode(',', array_keys($s)), "\n";
    $u = []; $u[3] = mk1('u3'); $u[2] = mk1('u2'); uasort($u, fn ($x, $y) => strcmp($x[0]->n, $y[0]->n)); echo implode(',', array_keys($u)), "\n";
    $b = new Box(); $b->items[3] = new Tok('p3'); $b->items[2] = new Tok('p2'); krsort($b->items); echo implode(',', array_keys($b->items)), "\n";
}
sorts();
echo "after sorts\n";
function byValue(array $p): int { krsort($p); return \count($p); }
function caller(): void { $a = [1 => new Tok('v1'), 3 => new Tok('v3')]; echo byValue($a), "\n"; echo implode(',', array_keys($a)), "\n"; }
caller();
echo "end\n";
