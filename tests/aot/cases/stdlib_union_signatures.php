<?php

// strtr: both of php's forms, pairs longest-first, numeric keys
echo strtr('Hi all, I said hello', ['Hi' => 'Hello', 'hello' => 'hi', 'Hello' => 'x']), "\n";
echo strtr('1 2 3', [1 => 'one', '2' => 'two']), "\n";
echo strtr('hello world', 'lo', 'xy'), "\n";
/** @var array<string, string> $map */
$map = ['{a}' => 'A', '{bb}' => 'B'];
echo strtr('<{a}|{bb}|{c}>', $map), "\n";
foreach ([fn () => strtr('a', 'b'), fn () => strtr('a', ['a' => 'b'], 'c')] as $f) {
    try { $f(); } catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

// preg_replace / preg_replace_callback: pattern lists, array subjects
echo preg_replace(['/a/', '/b/'], ['b', 'c'], 'ab'), "\n";
echo preg_replace(['/(?<=[a-z])([A-Z])/', '/\s+/'], ['_$1', '-'], 'fooBar baz'), "\n";
echo implode(',', preg_replace('/\d/', '#', ['x' => 'a1', 'y' => 'b22'])), "\n";
echo preg_replace('/o/', '0', 'foo', 1, $n), " $n\n";
echo preg_replace_callback(['/a/', '/b/'], fn (array $m): string => strtoupper($m[0]), 'abc'), "\n";
var_dump(preg_replace_callback('/\d+/', fn (array $m): string => (string)((int)$m[0] * 2), ['k' => 'n=21']));

// str_replace / str_ireplace over an array subject, keyed search order
var_dump(str_replace('a', 'b', ['x' => 'aa', 'y' => 'ba']));
echo str_replace([2 => 'a', 0 => 'b'], ['1', '2'], 'ab', $c), " $c\n";
echo str_replace('', 'x', 'abc'), "\n";
var_dump(str_ireplace('A', '-', ['Aa', 'bA']));

// filter_var with the options array
var_dump(filter_var('5', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]));
var_dump(filter_var('-1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]));
var_dump(filter_var('x', FILTER_VALIDATE_INT, ['options' => ['default' => 7]]));
var_dump(filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']]));

// proc_open with an argv array: no shell, so the `$HOME` stays literal
$p = proc_open(['printf', '%s|%s', 'a b', '$HOME'], [1 => ['pipe', 'w']], $pipes);
echo stream_get_contents($pipes[1]), "\n";
fclose($pipes[1]);
echo proc_close($p), "\n";
