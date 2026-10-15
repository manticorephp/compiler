<?php
// A fresh object argument must be dropped when the callee throws (not only when it returns).
class O { public array $a; function __construct(public string $s) { $this->a = [$s, $s . "y"]; } static function mk(string $s): O { return new O($s); } }
class D { function __destruct() { echo "d\n"; } }
function thrower(O $o): int { throw new \RuntimeException("x"); }
function ok(O $o): int { return 1; }
function td(D $d): int { throw new \RuntimeException("x"); }
function t1(string $p): int { try { thrower(new O($p)); } catch (\RuntimeException $e) { return 1; } return 0; }
function t2(string $p): int { try { thrower(O::mk($p)); } catch (\RuntimeException $e) { return 1; } return 0; }
function t3(string $p): int { return ok(new O($p)) + ok(O::mk($p)); }
foreach (['t1', 't2', 't3'] as $f) {
    for ($i = 0; $i < 200; $i++) { $f("/x" . $i % 7); }
    $b = memory_get_usage();
    for ($i = 0; $i < 50000; $i++) { $f("/x" . $i % 7); }
    echo $f, " ", (memory_get_usage() - $b) < 300000 ? "flat" : "leak", "\n";
}
try { td(new D); } catch (\RuntimeException $e) {}
echo "end\n";
function mkT(string $s): \Throwable { return new \RuntimeException($s); }
function sink(\Throwable $t): int { return 1; }
function t4(string $p): int { return sink(mkT($p)); }
function t5(string $p): int { try { thrower2(mkT($p)); } catch (\RuntimeException $e) { return 1; } return 0; }
function thrower2(\Throwable $t): int { throw new \RuntimeException("y"); }
foreach (['t4', 't5'] as $f) {
    for ($i = 0; $i < 200; $i++) { $f("/x" . $i % 7); }
    $b = memory_get_usage();
    for ($i = 0; $i < 50000; $i++) { $f("/x" . $i % 7); }
    echo $f, " ", (memory_get_usage() - $b) < 300000 ? "flat" : "leak", "\n";
}
