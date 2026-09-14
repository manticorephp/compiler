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

// `unset($v)` on a variable a storable reference was taken to breaks the
// BINDING and nothing else: the name detaches, every earlier holder keeps the
// box. The deepclone idiom relies on each iteration's `&$value` being new.
function hard(mixed $values): array
{
    $pool = [];
    foreach ($values as $k => $value) {
        $values[$k] = &$value;
        unset($value);
        $value = $values[$k];
        $pool[] = [&$value];
    }
    $pool[0][0] = 'first';
    $pool[1][0] = 'second';
    return [$values, $pool];
}
[$vals, $pl] = hard(['a' => 1, 'b' => 2]);
var_dump($vals['a'], $vals['b'], $pl[0][0], $pl[1][0]);

// A chained store into two cell arrays: the inner store's VALUE is the raw
// operand, not the boxed word the slot took.
final class R { public function __construct(public int $id) {} }
function chain(mixed $values): array
{
    $refs = $values;
    foreach ($values as $k => $value) {
        $refs[$k] = $values[$k] = new R(-$k);
    }
    // (An int-keyed chained store appended AFTER this loop — `$refs[2] =
    // $values[2] = 42` — SIGSEGVs on main: the later int-key mutation changes
    // what inference calls $values, and the loop prologue then reads the boxed
    // word raw. Pre-existing, not a reference or alias problem.)
    return [$refs[1]->id, $values[1]->id, $refs[1] === $values[1]];
}
var_dump(chain([1 => 'x']));

// The witness itself, end to end.
function prepare2(mixed $values, array &$refsPool): array
{
    $sentinel = new stdClass();
    $refs = $values;
    foreach ($values as $k => $value) {
        $refs[$k] = $sentinel;
        if ($values[$k] !== $sentinel) {
            $values[$k] = &$value;
            unset($value);
            $refs[$k] = $value = $values[$k];
            $refsPool[] = [&$refs[$k], $value, &$value];
            $refs[$k] = $values[$k] = new R(-count($refsPool));
        }
    }
    return $refs;
}
$q = 10; $w = 'x';
$src = ['p' => &$q, 'q' => &$w, 'r' => 3];
$rp = [];
$res = prepare2($src, $rp);
echo count($rp), "\n";
foreach ($res as $k => $v) { echo $k, '=', $v instanceof R ? 'R#' . $v->id : get_class($v), "\n"; }
$rp[0][0] = 'rewritten';
var_dump($res['r']);

// `$values[$k] = &$value` — the element as the TARGET of a reference
// assignment. It rebinds the callee's element and leaves the caller's
// `'p' => &$a` exactly as it was: the `mixed` param took its own share of the
// buffer on entry, so the first store through it copied. This is deepclone's
// "break hard reference", and every value here is php's.
function brk(mixed $values): array
{
    foreach ($values as $k => $value) {
        $values[$k] = &$value;
        unset($value);
        $values[$k] = 'replaced';
    }
    return $values;
}
$ha = 10;
$hin = ['p' => &$ha, 'r' => 3];
$hout = brk($hin);
var_dump($ha, $hin['p'], $hout['p'], $hout['r']);
