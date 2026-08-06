<?php

// @epic: byref-return
// @why: VALUE context on a by-reference return of an ARRAY must yield a COPY.
//       It aliases instead, and the in-place append then reallocates under the
//       property -- the owner reads EMPTY, not the original. Older and broader
//       than the closure by-ref work: the NAMED form below is the plain witness,
//       and tests/aot/cases/byref_method_return.php only ever reads a SCALAR in
//       value context, which is why this went uncovered.

class H
{
    /** @var array<int,string> */
    public array $items = ['a'];
}

function &g(H $h): array { return $h->items; }

$h = new H();

// `$copy = g($h)` is an ASSIGNMENT, not a reference binding, so php copies.
$copy = g($h);
$copy[] = 'b';

echo implode('|', $h->items), "\n";   // php: a      -- native prints nothing
echo implode('|', $copy), "\n";       // php: a|b

// The method form diverges the same way.
class M
{
    /** @var array<int,string> */
    public array $rows = ['r0'];

    public function &ref(): array { return $this->rows; }
}

$m = new M();
$c2 = $m->ref();
$c2[] = 'r1';
echo implode('|', $m->rows), "\n";    // php: r0
echo implode('|', $c2), "\n";         // php: r0|r1

// A SCALAR in value context is correct today, and is the reason the gap hid.
class S
{
    public int $n = 7;
    public function &ref(): int { return $this->n; }
}

$s = new S();
$v = $s->ref();
$v = $v + 1;
echo $s->n, ",", $v, "\n";            // php: 7,8
