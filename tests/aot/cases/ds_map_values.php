<?php
use Manticore\Ds\Map;

/** @var Map<string, mixed> $m */
$m = new Map();
$m['n'] = null; $m['f'] = 0.1 + 0.2; $m['b'] = false; $m['a'] = [1, [2, 3]]; $m['s'] = str_repeat('x', 3);
var_dump($m->has('n'), $m->get('n', 'dflt'), $m['f'] === 0.1 + 0.2, $m['b'], $m['a'][1][0], $m['s']);
$m['f'] = 2.0 ** 60;
var_dump($m['f']);
var_dump($m);
