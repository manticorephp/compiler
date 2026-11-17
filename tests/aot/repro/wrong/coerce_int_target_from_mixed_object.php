<?php
// A mixed object bound to an int parameter, return or typed property yields its address instead of a TypeError.
// issue: #734
class Foo { public int $n = 0; }
function i(int $x): int { return $x; }
function r(mixed $v): int { return $v; }
function m(mixed $v): mixed { return $v; }
function t(string $l, callable $f): void { try { $f(); echo $l, ": no error\n"; } catch (\Throwable $e) { echo $l, ": ", explode(", called in", $e->getMessage())[0], "\n"; } }
t("param", fn() => i(m(new Foo())));
t("return", fn() => r(m(new Foo())));
t("prop", function () { $o = new Foo(); $o->n = m(new Foo()); });
