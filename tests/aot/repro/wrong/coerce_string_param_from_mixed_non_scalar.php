<?php
// A mixed null/array bound to a string parameter is rendered instead of throwing TypeError, and an object without __toString throws the cast Error text.
// issue: #733
class NoStr {}
function s(string $x): string { return "[" . $x . "]"; }
function m(mixed $v): mixed { return $v; }
function t(string $l, callable $f): void { try { $f(); echo $l, ": no error\n"; } catch (\Throwable $e) { echo $l, ": ", explode(", called in", $e->getMessage())[0], "\n"; } }
t("null", fn() => s(m(null)));
t("array", fn() => s(m([1])));
t("object", fn() => s(m(new NoStr())));
