<?php
// A string-keyed array still holds INT entries: "0" and "-7" canonicalise on
// the store, and a packed buffer has only indexes. The foreach key var is
// typed string and used to receive the raw int word as a pointer — key 0 read
// as NULL, and `$_FILES[$k] = $v` over it SIGSEGVed.

/** @return array<string, mixed> */
function mk(): array
{
    $o = ['a' => 1];
    $o["0"] = 'x';
    $o["-7"] = 'y';
    $o['b'] = 2;
    return $o;
}

/** @param array<string, mixed> $in */
function keys(array $in): void
{
    foreach ($in as $k => $v) {
        echo 'k=', $k, ' len=', strlen($k), ' v=', $v, "\n";
    }
}

keys(mk());

/** @param array<string, string> $in */
function keys2(array $in): void
{
    foreach ($in as $k => $v) {
        echo 'k=', $k, ' len=', strlen($k), ' v=', $v, "\n";
    }
}

$s = ['x' => 'a'];
$s[] = 'b';
$s[] = 'c';
keys2($s);
