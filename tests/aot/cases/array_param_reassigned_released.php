<?php
// A by-value ARRAY parameter the body reassigns from an owned producer kept the
// blanket param block: `$tokens = array_values(array_filter($tokens, …))`
// stored a fresh array nobody gave back (php-cs-fixer's sibling search).
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
/** @param list<Tok> $t */
function keep(array $t): int { $t = array_values(array_filter($t, fn (Tok $x): bool => $x->n !== 'b')); return \count($t); }
/** @param list<Tok> $t */
function wrap(array $t): int { $t = [...$t, new Tok('extra')]; return \count($t); }
function run(): void {
    $in = [new Tok('a'), new Tok('b')];
    echo keep($in), "\n";
    echo "after keep\n";
    echo wrap($in), "\n";
    echo "after wrap\n";
    echo \count($in), "\n";
    unset($in);
    echo "end\n";
}
run();
