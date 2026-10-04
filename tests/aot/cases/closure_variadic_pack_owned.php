<?php
// A closure's variadic pack is the call site's temp: the callee borrows it,
// so the pack — typed, untyped, or rebuilt from cells — is given back after
// the call, once.
final class O { public function __construct(public string $t) {} public function __destruct() { echo "~{$this->t}\n"; } }
function mk(string $t): mixed { return new O($t); }
function run(): void {
    $u = function (...$xs) { echo count($xs), "\n"; };
    $u(new O('a'), new O('b'));
    echo "after-u\n";
    $t = function (O ...$xs) { echo $xs[0]->t, "\n"; };
    $t(new O('c'), new O('d'));
    echo "after-t\n";
    $m = function (O ...$xs) { echo count($xs), "\n"; };
    $m(mk('e'), new O('f'));
    echo "after-m\n";
    $keep = [];
    $k = function (O ...$xs) use (&$keep) { $keep = $xs; };
    $k(new O('g'));
    echo "kept\n";
    $keep = [];
    echo "after-k\n";
}
run();
echo "end\n";
