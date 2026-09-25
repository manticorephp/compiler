<?php
// array_any / array_all / array_find / array_find_key hand the predicate
// ($value, $key), as php 8.4 does.
$o = ['' => true, "\n" => true];
$n = ['' => true, "\n" => true];
var_dump(array_any($n, static fn (bool $set, string $break) => !isset($o[$break])));
var_dump(array_any($n, static fn ($set, $break) => !isset($o[$break])));
var_dump(array_any($n, fn (bool $set, string $break) => $break === 'zz'));
var_dump(array_any([1, 2], fn (int $v, int $k) => $v === 2));
var_dump(array_any(['a' => 1], fn ($v, $k) => $k === 'a'));
var_dump(array_all(["a" => 1, "b" => 2], fn ($v, $k) => $k !== "c"));
var_dump(array_find(["a" => 1, "b" => 2], fn ($v, $k) => $k === "b"));
var_dump(array_find_key(["a" => 1, "b" => 2], fn ($v, $k) => $v === 2 && $k === "b"));
