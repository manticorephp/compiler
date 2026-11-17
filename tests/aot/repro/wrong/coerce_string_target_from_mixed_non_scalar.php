<?php
// A mixed null/array/object stored to a string return or typed property passes raw bytes instead of a TypeError.
class Foo { public string $s = ''; }
function r(mixed $v): string { return $v; }
function m(mixed $v): mixed { return $v; }
function t(string $l, callable $f): void { try { $f(); echo $l, ": no error\n"; } catch (\Throwable $e) { echo $l, ": ", explode(", called in", $e->getMessage())[0], "\n"; } }
t("return null", fn() => r(m(null)));
t("return array", fn() => r(m([1])));
t("return object", fn() => r(m(new Foo())));
t("prop null", function () { $o = new Foo(); $o->s = m(null); });
t("prop array", function () { $o = new Foo(); $o->s = m([1]); });
