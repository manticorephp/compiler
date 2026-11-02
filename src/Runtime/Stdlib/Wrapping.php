<?php

namespace Manticore;

// Opt-in two's-complement arithmetic: the int result modulo 2^64, never a float and never an
// overflow error. The compiler inlines each call as a plain `add` / `sub` / `mul i64` (a codegen
// builtin, EmitLlvmBuiltins::biWrapping); these bodies are the 32-bit-limb twins that an older
// compiler links and that stay exact under any int semantics.

/** `$a + $b` modulo 2^64. */
function wrapping_add(int $a, int $b): int
{
    return \__mc_xxh_add($a, $b);
}

/** `$a - $b` modulo 2^64. */
function wrapping_sub(int $a, int $b): int
{
    return \__mc_xxh_sub($a, $b);
}

/** `$a * $b` modulo 2^64. */
function wrapping_mul(int $a, int $b): int
{
    return \__mc_xxh_mul($a, $b);
}
