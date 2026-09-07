<?php

final class S { public function __construct(public readonly int $k) {} }

final class P {
    /** @param S[] $st */
    public function __construct(public readonly array $st) {}
}

function boom(): int { throw new \RuntimeException('boom'); }

// A local ASSIGNED inside a try must still hold that value on the catch path.
// A try is _setjmp and a throw is _longjmp, which restores the callee-saved
// registers to what they held at the setjmp — so every slot -O2 promoted out
// of memory reverted to its pre-try value and the catch read the wrong one.
function scalars(): string
{
    $s = 'before';
    $n = 1;
    try {
        $s = 'inside';
        $n = 2;
        boom();
    } catch (\Throwable $e) {
        return $s . '/' . (string)$n . '/' . $e->getMessage();
    }
    return 'unreached';
}

// The refcount half of the same defect: `$stmts = []` releases the buffer the
// P still owns, so a reverted slot made the catch release it a SECOND time and
// P's drop then freed a pointer that was no longer allocated.
function owned(int $n): ?int
{
    $stmts = [];
    for ($i = 0; $i < $n; $i = $i + 1) { $stmts[] = new S($i); }
    $program = new P($stmts);
    try {
        $stmts = [];
        boom();
        return 0;
    } catch (\Throwable $e) {
        return \count($program->st) + \count($stmts);
    }
}

echo scalars(), "\n";
var_dump(owned(500));
