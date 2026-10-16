<?php
function t(callable $f): void
{
    try { var_dump($f()); } catch (ValueError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
t(fn() => str_pad('a', 5, ''));
t(fn() => str_pad('a', 5, 'x', 7));
t(fn() => str_pad('a', 5, 'x', -1));
t(fn() => str_pad('abcdef', 3, ''));
t(fn() => str_pad('a', 5, 'xy', STR_PAD_BOTH));
t(fn() => hex2bin('abc'));
t(fn() => hex2bin('zz'));
t(fn() => hex2bin('4a4B'));
t(fn() => hex2bin(''));
t(fn() => strtr('abc', ['' => 'x']));
t(fn() => strtr('abc', ['a' => 'x']));
