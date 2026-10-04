<?php
// A global whose element another scope stores a second kind into keeps a
// cell-element view in EVERY scope — including the one that assigned it a
// concrete literal, read back after the call that stored the int. And
// $_ENV is array<string, mixed> from its seed, like $_SERVER.
$g = ["a" => "b"];
function setv(): void { global $g; $g["ZZ_V"] = 3; }
setv();
var_dump($g["ZZ_V"], $g["a"]);
$g = ["c" => "d"];
setv();
var_dump($g);

$_ENV["ZZ_T"] = 3;
var_dump($_ENV["ZZ_T"]);
$_ENV["ZZ_F"] = 1.5;
$_ENV["ZZ_A"] = [1, "x"];
var_dump($_ENV["ZZ_F"], $_ENV["ZZ_A"], is_string($_ENV["PATH"] ?? ""));

function env_int(): void { $_ENV["ZZ_I"] = 42; }
function env_read(): int { return $_ENV["ZZ_I"]; }
env_int();
var_dump(env_read(), $_ENV["ZZ_I"]);

$list = [1, 2];
function addstr(): void { global $list; $list[] = "three"; }
addstr();
var_dump($list[2], $list[0] + 1);
