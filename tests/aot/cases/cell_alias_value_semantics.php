<?php

// `$b = $a` where `$a` is a CELL local (`mixed`): php arrays are VALUES, so a
// write through one name must never reach the other. It did: the alias left
// the array at rc 1 with two names on it, so the copy-on-write behind
// `$b['r'] = …` saw a sole owner and wrote in place. Every line here is
// measured against the interpreter.

function f(mixed $values): void
{
    $s = new stdClass();
    $refs = $values;
    $refs['r'] = $s;
    var_dump($values['r'] !== $s, $values['r']);
    var_dump($refs['r'] === $s);
}
f(['r' => 3]);

// The other direction: mutate the SOURCE after the alias.
function g(mixed $values): array
{
    $copy = $values;
    $values['r'] = 'changed';
    return [$copy['r'], $values['r']];
}
var_dump(g(['r' => 'orig']));

// A chain of aliases, each independent.
function h(mixed $v): string
{
    $a = $v; $b = $a; $c = $b;
    $a['k'] = 'A'; $b['k'] = 'B'; $c['k'] = 'C';
    return $v['k'] . $a['k'] . $b['k'] . $c['k'];
}
echo h(['k' => 'V']), "\n";

// The alias survives the caller's later mutation of ITS array — the borrowed
// parameter's count is not the callee's to spend.
function keep(mixed $v): array { $mine = $v; $mine['x'] = 'mine'; return $mine; }
$outer = ['x' => 'outer', 'y' => 1];
$kept = keep($outer);
$outer['x'] = 'after';
$outer['z'] = 2;
var_dump($kept['x'], $outer['x'], count($kept), count($outer));

// A string and an object through the same alias path: retained, not copied,
// and still one value.
function strobj(mixed $s, mixed $o): array { $t = $s; $p = $o; $p->n = 5; return [$t, $p]; }
$o = new stdClass(); $o->n = 1;
[$t, $p] = strobj('hello', $o);
var_dump($t, $p->n, $o->n, $p === $o);

// The deepclone predicate: write a sentinel into the alias, test the source.
function prepare(mixed $values, array &$pool): array
{
    $sentinel = new stdClass();
    $refs = $values;
    foreach ($values as $k => $value) {
        $refs[$k] = $sentinel;
        if ($values[$k] !== $sentinel) {
            $pool[] = [&$refs[$k], $value, &$value];
        }
    }
    return $refs;
}
$a = 10;
$in = ['p' => &$a, 'r' => 3];
$pool = [];
$out = prepare($in, $pool);
var_dump(count($pool));
