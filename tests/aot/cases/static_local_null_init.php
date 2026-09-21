<?php
// `static $x = null;` is `static $x;` — the store, not the null, types the slot;
// a scalar-stored static rides a cell so its null start stays observable.
class Foo { public int $n = 0; }
function inst(): Foo {
    static $inst = null;
    if ($inst === null) { echo "new\n"; $inst = new Foo(); }
    $inst->n++;
    return $inst;
}
function tab(): array {
    static $t = null;
    if ($t === null) { echo "tab\n"; $t = ['a' => 1, 'b' => 2]; }
    return $t;
}
function str(): string {
    static $s = null;
    if ($s === null) { echo "str\n"; $s = "str"; }
    return $s;
}
function fl(): float {
    static $f = null;
    if ($f === null) { echo "fl\n"; $f = 1.5; }
    return $f;
}
function cnt(): int {
    static $c = null;
    if ($c === null) { $c = 5; }
    $c++;
    return $c;
}
function cnt2(): int {
    static $c;
    if (!isset($c)) { $c = 10; }
    $c = $c + 1;
    return $c;
}
function bl(): bool {
    static $b = null;
    if ($b === null) { echo "bl\n"; $b = true; }
    return $b;
}
function mixed_(int $i): mixed {
    static $m = null;
    if ($m === null) { $m = $i; } else { $m = "s" . $m; }
    return $m;
}
function untouched(): ?string {
    static $u = null;
    return $u;
}
function isnull(mixed $x): bool { return isset($x); }
echo inst()->n, inst()->n, inst()->n, "\n";
echo tab()['b'], tab()['a'], "\n";
echo str(), str(), str(), "\n";
echo fl(), " ", fl(), "\n";
echo cnt(), cnt(), cnt(), "\n";
echo cnt2(), cnt2(), cnt2(), "\n";
var_dump(bl(), bl());
echo mixed_(1), " ", mixed_(2), " ", mixed_(3), "\n";
var_dump(untouched());
var_dump(isnull(null), isnull(0), isnull("a"));
