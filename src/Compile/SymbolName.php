<?php

namespace Compile;

/**
 * How a php name becomes a name the toolchain accepts. Both encodings are
 * injective: `A\B_C` and `A_B\C` are different classes (sodium_compat ships one
 * class under both spellings), and an encoding that merged them redefined
 * every symbol the pair shares.
 */
final class SymbolName
{
    /**
     * The LLVM symbol fragment of a php name. `\` becomes `.`, which no php name
     * contains; a byte >= 0x80 (php admits them in names — symfony/cache declares
     * `class \xa9`) becomes `_u<HEX>`.
     */
    public static function mangle(string $name): string
    {
        /** @var string[] $parts */
        $parts = [];
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($name, $i, 1);
            if ($c === '\\') { $parts[] = '.'; continue; }
            $b = \ord($c);
            $parts[] = $b >= 0x80 ? '_u' . \strtoupper(\dechex($b)) : $c;
        }
        return \implode('', $parts);
    }

    /**
     * A php name (leading `\` dropped) as a fragment of a SYNTHESIZED php
     * function name (`__mc_rtramp_<class>__<method>`): `_` becomes `_0` and `\`
     * becomes `_1`, so the fragment is a valid identifier, never contains `__`
     * (the class/member separator stays unambiguous) and two names never meet.
     */
    public static function ident(string $name): string
    {
        return \str_replace(['_', '\\'], ['_0', '_1'], \ltrim($name, '\\'));
    }
}
