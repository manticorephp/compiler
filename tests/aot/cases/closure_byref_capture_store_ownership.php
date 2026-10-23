<?php
class P { public string $name = "prop"; public array $list = ["e0", "e1"]; }
function a(string $x) { $t = "init"; $f = function () use (&$t, $x) { $t = $x; }; $f(); $f(); return $t . $x; }
function b(P $p) { $t = "init"; $f = function () use (&$t, $p) { $t = $p->name; }; $f(); $f(); $p->name = "changed"; return $t; }
function c(P $p) { $t = "init"; $f = function () use (&$t, $p) { $t = $p->list[1]; }; $f(); $f(); $p->list = []; return $t; }
function d() { $t = "init"; $f = function () use (&$t) { $t = $t; $t = $t . "+"; $t = str_repeat($t, 2); }; $f(); $f(); return $t; }
function e(string $x) { $t = "init"; $f = function (string $v) use (&$t) { $t = $v; }; $f($x); $f($x . "!"); $f($x); return $t; }
function g() { $t = "init"; $f = function () use (&$t) { $t = $t === "init" ? "second" : "third"; }; $f(); $f(); unset($f); return $t; }
function h(array $words) { $t = ""; $f = function (string $w) use (&$t) { $t = $t . $w; }; foreach ($words as $w) { $f($w); } return $t; }
function k() { $o = new P; $t = $o; $f = function () use (&$t) { $t = new P; $t->name = "fresh"; }; $f(); $f(); return $t->name . $o->name; }
function m() { $t = [1]; $f = function () use (&$t) { $t = [2, 3]; $t[] = 4; }; $f(); $f(); return implode(",", $t); }
for ($i = 0; $i < 3; $i++) {
    echo a("A$i"), " ", b(new P), " ", c(new P), " ", d(), " ", e("E$i"), " ", g(), " ", h(["x", "y$i", "z"]), " ", k(), " ", m(), "\n";
}
