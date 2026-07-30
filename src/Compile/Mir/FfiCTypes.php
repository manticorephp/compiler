<?php

namespace Compile\Mir;

/**
 * The `#[Ffi\CType]` vocabulary: one table mapping a C type token to the LLVM
 * type the FFI wrapper declares for it, plus its signedness.
 *
 * It lives here rather than in the lowering pass because two consumers read it —
 * {@see Passes\LowerFromAst} decides the wrapper's declared types, and
 * {@see Passes\EmitLlvmCalls} picks the boundary conversion from the LLVM type —
 * and a table duplicated in two places is a table that drifts.
 *
 * ⚠ LP64. `long`, `size_t`, `ssize_t` and `off_t` are 64 bits here. That is not
 * a portability bug waiting to happen: manticore compiles for the HOST, there is
 * no cross-compile, and every target it builds for (arm64/x86_64 Darwin and
 * Linux) is LP64. A Windows LLP64 port would have to revisit this table, and
 * nothing else.
 *
 * The token set is deliberately closed — an unknown token is a compile error,
 * not a silent fallback. Silently ignoring one is how `'unsigned int'` and
 * `'nfds_t'` got written and stayed unnoticed for as long as they did. Platform
 * typedefs get NO alias on purpose: resolving `nfds_t`'s width is the binding
 * author's job, and the error is what makes them do it.
 */
final class FfiCTypes
{
    /**
     * The LLVM type for a C type token, or '' when the token is unknown.
     * Multi-word C spellings (`unsigned int`, `long long`) are accepted as
     * aliases of their one-word forms.
     */
    public static function llvmType(string $token): string
    {
        $t = self::canon($token);
        if ($t === 'void')   { return 'void'; }
        if ($t === 'bool')   { return 'i1'; }
        if ($t === 'char' || $t === 'uchar')   { return 'i8'; }
        if ($t === 'short' || $t === 'ushort') { return 'i16'; }
        if ($t === 'int' || $t === 'uint')     { return 'i32'; }
        if ($t === 'long' || $t === 'ulong' || $t === 'longlong'
            || $t === 'ulonglong' || $t === 'size_t' || $t === 'ssize_t'
            || $t === 'off_t') { return 'i64'; }
        if ($t === 'float')  { return 'float'; }
        if ($t === 'double') { return 'double'; }
        if ($t === 'ptr')    { return 'ptr'; }
        return '';
    }

    /**
     * Whether the token names an UNSIGNED integer, so a narrow return is
     * ZERO-extended into the i64 carrier instead of sign-extended. `size_t` is
     * unsigned in C but maps to i64, where neither extension applies — it is
     * reported honestly anyway so a future narrower mapping stays correct.
     */
    public static function isUnsigned(string $token): bool
    {
        $t = self::canon($token);
        return $t === 'uchar' || $t === 'ushort' || $t === 'uint'
            || $t === 'ulong' || $t === 'ulonglong' || $t === 'size_t'
            || $t === 'bool';
    }

    /** True for a token that carries a raw address rather than a number. */
    public static function isPointer(string $token): bool
    {
        return self::canon($token) === 'ptr';
    }

    /** True for a token that names a C floating-point type. */
    public static function isFloat(string $token): bool
    {
        $t = self::canon($token);
        return $t === 'float' || $t === 'double';
    }

    /** The known tokens, in the order the error message should list them. */
    public static function tokens(): string
    {
        return 'void, bool, char, uchar, short, ushort, int, uint, long, ulong, '
            . 'longlong, ulonglong, size_t, ssize_t, off_t, float, double, ptr';
    }

    /**
     * Fold a written token to its canonical spelling: lowercased, with runs of
     * whitespace collapsed, and the multi-word C forms mapped onto the one-word
     * ones. An unrecognised spelling is returned as-is so `llvmType` can reject it.
     */
    private static function canon(string $token): string
    {
        $t = \strtolower(\trim($token));
        $out = '';
        $prevSpace = false;
        $n = \strlen($t);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($t, $i, 1);
            $isSpace = $c === ' ' || $c === "\t";
            if ($isSpace) {
                if (!$prevSpace) { $out .= ' '; }
                $prevSpace = true;
                continue;
            }
            $prevSpace = false;
            $out .= $c;
        }
        if ($out === 'unsigned' || $out === 'unsigned int') { return 'uint'; }
        if ($out === 'unsigned char')      { return 'uchar'; }
        if ($out === 'signed char')        { return 'char'; }
        if ($out === 'unsigned short' || $out === 'unsigned short int') { return 'ushort'; }
        if ($out === 'short int')          { return 'short'; }
        if ($out === 'unsigned long' || $out === 'unsigned long int')   { return 'ulong'; }
        if ($out === 'long int')           { return 'long'; }
        if ($out === 'long long' || $out === 'long long int')           { return 'longlong'; }
        if ($out === 'unsigned long long' || $out === 'unsigned long long int') {
            return 'ulonglong';
        }
        if ($out === 'pointer' || $out === 'void*') { return 'ptr'; }
        return $out;
    }
}
