<?php

// Manticore\Overloadable — opt-in operator overloading for a class. A statically typed
// operand of an implementing class turns `$a + $b`, `2 ** $a`, `-$a`, `$a < $b`, `$a == $b`,
// `$a <=> $b` into calls of the two methods below (Compile\Mir\Passes\InlineClosures::tryOperator).
// `===` / `!==` stay identity. A superset feature: Zend has no userland overloading.
namespace Manticore {

    interface Overloadable
    {
        /**
         * @param string $op one of '+' '-' '*' '/' '%' '**'; unary minus arrives as `* -1`
         * @param bool $swapped true when this object was the RIGHT operand (`2 - $this`)
         */
        public function __operator(string $op, mixed $other, bool $swapped): static;

        /** Negative / zero / positive as `$this <=> $other`; drives `< <= > >= == !=` too. */
        public function __compare(mixed $other): int;
    }
}

// Run-time dispatch for an operand whose class is erased (mixed / untyped): the
// static rewrite in InlineClosures::tryOperator cannot see the hook, so it calls these.
// Their own fallback operators are exempt from that rewrite (the `__mc_overload_` prefix).
namespace {

    function __mc_overload_op(string $op, mixed $x, mixed $y): mixed
    {
        if ($x instanceof \Manticore\Overloadable) { return $x->__operator($op, $y, false); }
        if ($y instanceof \Manticore\Overloadable) { return $y->__operator($op, $x, true); }
        if ($op === '+') { return $x + $y; }
        if ($op === '-') { return $x - $y; }
        if ($op === '*') { return $x * $y; }
        if ($op === '/') { return $x / $y; }
        if ($op === '%') { return $x % $y; }
        return $x ** $y;
    }

    function __mc_overload_spaceship(mixed $x, mixed $y): int
    {
        if ($x instanceof \Manticore\Overloadable) { return $x->__compare($y); }
        if ($y instanceof \Manticore\Overloadable) { return -$y->__compare($x); }
        return $x <=> $y;
    }

    function __mc_overload_cmp(string $op, mixed $x, mixed $y): bool
    {
        if ($x instanceof \Manticore\Overloadable) { $c = $x->__compare($y); }
        elseif ($y instanceof \Manticore\Overloadable) { $c = -$y->__compare($x); }
        else {
            if ($op === '<') { return $x < $y; }
            if ($op === '<=') { return $x <= $y; }
            if ($op === '>') { return $x > $y; }
            if ($op === '>=') { return $x >= $y; }
            if ($op === '==') { return $x == $y; }
            return $x != $y;
        }
        if ($op === '<') { return $c < 0; }
        if ($op === '<=') { return $c <= 0; }
        if ($op === '>') { return $c > 0; }
        if ($op === '>=') { return $c >= 0; }
        if ($op === '==') { return $c === 0; }
        return $c !== 0;
    }
}
