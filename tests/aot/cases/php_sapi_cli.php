<?php

// PHP_SAPI is a runtime read (a compat Http\Server flips it); outside a server
// it must stay 'cli' through every place a constant can appear.

const TOP = PHP_SAPI;

class K
{
    const S = PHP_SAPI;
    public string $p = PHP_SAPI;
}

function f(string $x = PHP_SAPI): string
{
    return $x;
}

echo PHP_SAPI, "\n";
echo php_sapi_name(), "\n";
echo TOP, ' ', K::S, ' ', (new K())->p, ' ', f(), "\n";
var_dump(PHP_SAPI === php_sapi_name());
var_dump(in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true));
var_dump('cli' === \PHP_SAPI);
var_dump(constant('PHP_SAPI'), defined('PHP_SAPI'));
