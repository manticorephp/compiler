<?php
// A mixed string/null/array/object bound to a bool parameter is read by its low tag bit instead of php's truthiness or TypeError.
// issue: #732
class Foo {}
function b(bool $x): string { return $x ? "T" : "F"; }
function m(mixed $v): mixed { return $v; }
function t(string $l, callable $f): void { try { $f(); echo $l, ": no error\n"; } catch (\Throwable $e) { echo $l, ": ", explode(", called in", $e->getMessage())[0], "\n"; } }
foreach (['12', 'abc'] as $s) { echo b(m($s)), "\n"; }
t("null", fn() => b(m(null)));
t("array", fn() => b(m([1])));
t("object", fn() => b(m(new Foo())));
