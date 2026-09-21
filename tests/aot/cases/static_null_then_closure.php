<?php
// `static $h = null;` later holding a CLOSURE (or a callable name): the
// initialiser does not pin the decl to null — the store widens it, and the
// `=== null` test is a real test on every call.
function handler(): \Closure {
    return function (int $n): string { return "h" . $n; };
}
function call(int $n): string {
    static $h = null;
    if ($h === null) {
        echo "bind\n";
        $h = handler();
    }
    return $h($n);
}
function up(string $s): string { return strtoupper($s); }
function callable_(int $n): string {
    static $cb = null;
    if ($cb === null) {
        $cb = 'up';
    }
    return $cb("v" . $n);
}
echo call(7), "\n";
echo call(8), "\n";
echo callable_(1), " ", callable_(2), "\n";
$fn = function (int $n): string { return "x" . $n; };
function viaArg(?\Closure $f, int $n): string {
    static $h = null;
    if ($f !== null) { $h = $f; }
    return $h === null ? 'none' : $h($n);
}
echo viaArg($fn, 1), " ", viaArg(null, 2), "\n";
