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

