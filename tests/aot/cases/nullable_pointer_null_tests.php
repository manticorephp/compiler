<?php
// A null that shares a slot with an array or a string rides it as ptr 0 — a
// null-seeded local an if/switch/catch/loop may leave null, a `?array` return,
// a `?string` merge. Every null test answers it: `is_null`, `is_array` /
// `is_string`, `gettype`, `=== null`, `isset`, `??`.
function tests(string $tag, mixed $unused, bool $isNull, bool $isKind, string $type, bool $strict, bool $set): void
{
    echo $tag, ' ', $isNull ? 'N' : 'S', $isKind ? 'K' : '-', ' ', $type, ' ', $strict ? 'N' : 'S', $set ? 'S' : 'N', "\n";
}
function ifArr(bool $c): void
{
    $r = null;
    if ($c) { $r = [1, 2]; }
    tests('ifArr', null, is_null($r), is_array($r), gettype($r), $r === null, isset($r));
    echo count($r ?? []), "\n";
}
function swArr(int $k): void
{
    $r = null;
    switch ($k) { case 1: $r = ['a' => 1]; break; }
    tests('swArr', null, is_null($r), is_array($r), gettype($r), $r === null, isset($r));
}
function catchArr(bool $t): void
{
    $r = [3];
    try {
        if ($t) { throw new RuntimeException('x'); }
    } catch (RuntimeException $e) {
        $r = null;
    }
    tests('catchArr', null, is_null($r), is_array($r), gettype($r), $r === null, isset($r));
}
function loopArr(int $n): void
{
    $r = null;
    for ($i = 0; $i < $n; $i++) { $r = [$i]; }
    tests('loopArr', null, is_null($r), is_array($r), gettype($r), $r === null, isset($r));
}
function maybe(bool $c) { if ($c) { return [1]; } return null; }
function retArr(bool $c): void
{
    $r = maybe($c);
    tests('retArr', null, is_null($r), is_array($r), gettype($r), $r === null, isset($r));
}
function ifStr(bool $c): void
{
    $r = null;
    if ($c) { $r = 'x' . ($c ? 'y' : 'z'); }
    tests('ifStr', null, is_null($r), is_string($r), gettype($r), $r === null, isset($r));
    echo $r ?? 'dflt', "\n";
}
foreach ([true, false] as $c) { ifArr($c); catchArr($c); retArr($c); ifStr($c); }
swArr(1); swArr(2); loopArr(1); loopArr(0);
echo "done\n";
