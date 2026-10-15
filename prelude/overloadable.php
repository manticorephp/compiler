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
