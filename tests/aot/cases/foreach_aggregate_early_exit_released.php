<?php
// A foreach over an IteratorAggregate owns its getIterator() result and gave it
// back only at the loop's end label: a `return`, `break 2` or `continue 2` out
// of the body branched past it, and the iterator kept its subject — and every
// element of a SplFixedArray — alive forever (php-cs-fixer's
// StatementIndentationFixer returned out of `foreach ($tokens ...)` per file).
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
final class Toks extends \SplFixedArray {}
function mk(string $p): Toks { $t = new Toks(2); $t[0] = new Tok($p); $t[1] = 5; return $t; }
function ret(Toks $t): int { foreach ($t as $i => $tok) { if ($i === 1) { return $i; } } return -1; }
function nested(Toks $t): int { $s = [1, 2]; foreach ($t as $i => $tok) { while (true) { array_pop($s); if ([] === $s) { return 7; } break; } } return 0; }
function brk2(Toks $t): int { $k = 0; for ($j = 0; $j < 3; $j++) { foreach ($t as $i => $tok) { $k++; if ($i === 0) { break 2; } } } return $k; }
function cont2(Toks $t): int { $k = 0; for ($j = 0; $j < 3; $j++) { foreach ($t as $i => $tok) { $k++; continue 2; } } return $k; }
foreach (['ret', 'nested', 'brk2', 'cont2'] as $f) {
    $x = mk($f);
    echo $f, " = ", $f($x), "\n";
    unset($x);
    echo "after ", $f, "\n";
}
