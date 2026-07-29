<?php

// A `static` local inside a NAMESPACED function.
//
// The cell backing it is a module global named after the function, and the name
// was not sanitized: inside a namespace it came out as `@Ns\fn__sl_x`, and a
// backslash in an unquoted LLVM identifier does not parse — "expected '=' in
// global variable". Every static local in the tree happened to sit in a
// global-namespace file, so the entire class of function was unbuildable and
// nothing said so.
//
// Plain PHP, so `php` is the oracle here (difftest checks it), unlike most of
// what the same session added.

namespace App\Counters;

function next(): int
{
    static $n = 0;
    $n = $n + 1;
    return $n;
}

function hold(bool $write, int $value): int
{
    static $held = -1;
    if ($write) { $held = $value; }
    return $held;
}

/** A static in a namespaced CLASS method — the other half of the same name. */
final class Seq
{
    public function step(int $by): int
    {
        static $acc = 100;
        $acc = $acc + $by;
        return $acc;
    }
}

echo next(), next(), next(), "\n";

echo hold(false, 0), "\n";       // the initialiser, once
hold(true, 42);
echo hold(false, 0), "\n";       // ...and the value it kept

$s = new Seq();
echo $s->step(1), " ", $s->step(2), "\n";

// A second instance shares the method's static, as in PHP.
$t = new Seq();
echo $t->step(10), "\n";
