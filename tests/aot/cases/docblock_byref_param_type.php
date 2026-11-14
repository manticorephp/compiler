<?php
// A docblock that spells the by-ref marker (`@param T &$x`) types the param like `@param T $x`.

/**
 * @param string[] &$list
 * @param int &$count
 */
function push(array &$list, &$count, string $s): void
{
    $list[] = $s;
    $count = $count + strlen($s);
}

/** @param array<string, int> &$tally */
function bump(&$tally, string $k): void
{
    $tally[$k] = ($tally[$k] ?? 0) + 1;
}

$l = ['a'];
$c = 1;
push($l, $c, 'bc');
push($l, $c, 'def');
echo implode(',', $l), ' ', $c, "\n";

$t = [];
foreach (['x', 'y', 'x', 'z', 'x'] as $k) { bump($t, $k); }
foreach ($t as $k => $v) { echo $k, '=', $v, "\n"; }
