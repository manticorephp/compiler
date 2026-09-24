<?php

// #[Overload] is a Manticore superset feature: php runs the canonical function
// for every call, so this expected output is written by hand, not by php.

use Manticore\Attr\Overload;

/** Canonical: php's union signature, dispatching at run time. */
function shout(array|string $v): array|string
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $s) { $out[$k] = strtoupper((string)$s) . '!'; }
        return $out;
    }
    return 'canonical:' . strtoupper($v) . '!';
}

#[Overload('shout')]
function shout_str(string $v): string
{
    return 'overload:' . strtoupper($v) . '!';
}

/** @return mixed */
function erased(): mixed { return 'e'; }

echo shout('hi'), "\n";
echo strlen(shout('abc')), "\n";
$arr = shout(['a', 'b']);
echo implode(',', $arr), "\n";
echo shout(erased()), "\n";
$fn = 'shout';
echo $fn('dyn'), "\n";
