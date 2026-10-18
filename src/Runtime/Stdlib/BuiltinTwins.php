<?php

/**
 * Callable twins of codegen builtins. A direct call stays inline (emitBuiltin
 * is asked before the symbol table); these bodies exist so a call by RUNTIME
 * NAME (`$f = 'strlen'; $f('abc')`) finds a symbol to dispatch to.
 */


function ord(string $character): int
{
    return \ord($character);
}

function chr(int $codepoint): string
{
    return \chr($codepoint);
}


function intdiv(int $num1, int $num2): int
{
    return \intdiv($num1, $num2);
}

function floatval(mixed $value): float
{
    return \floatval($value);
}

function gettype(mixed $value): string
{
    return \gettype($value);
}

function get_debug_type(mixed $value): string
{
    return \get_debug_type($value);
}

function dechex(int $num): string
{
    return \dechex($num);
}


function str_repeat(string $string, int $times): string
{
    return \str_repeat($string, $times);
}

function strtolower(string $string): string
{
    return \strtolower($string);
}

function strtoupper(string $string): string
{
    return \strtoupper($string);
}

function spl_object_id(object $object): int
{
    return \spl_object_id($object);
}

function addslashes(string $string): string
{
    return \addslashes($string);
}

function strlen(string $string): int
{
    return \strlen($string);
}


/** @param \Countable|array<mixed> $value */
function mctwin_count(mixed $value, int $mode = 0): int
{
    return \count($value, $mode);
}

function mctwin_sizeof(mixed $value, int $mode = 0): int
{
    return \count($value, $mode);
}

function mctwin_abs(int|float $num): int|float
{
    return \abs($num);
}

function mctwin_sqrt(float $num): float
{
    return \sqrt($num);
}

function mctwin_floor(int|float $num): float
{
    return \floor($num);
}

function mctwin_ceil(int|float $num): float
{
    return \ceil($num);
}

function mctwin_round(int|float $num, int $precision = 0): float
{
    return \round($num, $precision);
}

function mctwin_pow(mixed $num, mixed $exponent): mixed
{
    // The inline builtin picks int^int vs float from the STATIC operand types.
    $n = $num + 0;
    $e = $exponent + 0;
    if (\is_int($n) && \is_int($e) && $e >= 0) { return \pow($n, $e); }
    return \pow((float)$n, (float)$e);
}

function mctwin_intval(mixed $value, int $base = 10): int
{
    if ($base === 10 || !\is_string($value)) { return \intval($value); }
    // The inline builtin reads the base only from a literal.
    if ($base === 16) { return \intval((string)$value, 16); }
    if ($base === 8) { return \intval((string)$value, 8); }
    if ($base === 2) { return \intval((string)$value, 2); }
    if ($base === 0) { return \intval((string)$value, 0); }
    $s = \ltrim($value);
    $neg = $s !== '' && $s[0] === '-';
    if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) { $s = \substr($s, 1); }
    $r = 0;
    for ($i = 0; $i < \strlen($s); $i = $i + 1) {
        $c = \ord(\strtolower($s[$i]));
        $d = $c >= 97 ? $c - 87 : $c - 48;
        if ($c < 48 || ($c > 57 && $c < 97) || $c > 122 || $d >= $base) { break; }
        $r = $r * $base + $d;
    }
    return $neg ? -$r : $r;
}

function mctwin_substr(string $string, int $offset, mixed $length = null): string
{
    return $length === null ? \substr($string, $offset) : \substr($string, $offset, $length);
}

function mctwin_max(mixed $value, mixed ...$values): mixed
{
    if ($values === []) { return \max($value); }
    $m = $value;
    foreach ($values as $v) { if ($v > $m) { $m = $v; } }
    return $m;
}

function mctwin_min(mixed $value, mixed ...$values): mixed
{
    if ($values === []) { return \min($value); }
    $m = $value;
    foreach ($values as $v) { if ($v < $m) { $m = $v; } }
    return $m;
}

/** @param array<mixed>|null $array */
function mctwin_implode(mixed $separator, mixed $array = null): string
{
    if (\is_array($separator)) { return \implode('', $separator); }
    return \implode((string)$separator, $array);
}

function mctwin_join(mixed $separator, mixed $array = null): string
{
    if (\is_array($separator)) { return \implode('', $separator); }
    return \implode((string)$separator, $array);
}

function mctwin_sprintf(string $format, mixed ...$values): string
{
    return \vsprintf($format, $values);
}
