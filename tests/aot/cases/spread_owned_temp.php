<?php
// A spread source that is an owned temp (a call result, a literal) dies once
// the merge co-owned its elements; a cell operand is unboxed, not walked as a
// buffer; and a source whose element hint disagrees with the literal's is
// converted, so the literal's release drops every element it holds.
final class K { public function __construct(public string $t) {} public function __destruct() { echo "~K {$this->t}\n"; } }
/** @return K[] */
function typed(): array { return [new K('t1'), new K('t2')]; }
function erased(): array { return [new K('e1'), new K('e2')]; }
function boxed(): mixed { return [new K('c'), 'k' => new K('d')]; }
function run(): void {
    $x = [...typed()];
    echo count($x), " ", $x[1]->t, "\n";
    $x = null;
    echo "x\n";
    $y = [1, ...boxed()];
    echo count($y), "\n";
    $y = null;
    echo "y\n";
    $a = [...erased()];
    echo count($a), "\n";
    $a = null;
    echo "a\n";
    $b = [...typed(), ...erased(), 'x'];
    echo count($b), "\n";
    $b = null;
    echo "b\n";
    $c = [...[[new K('n1')], [new K('n2')]]];
    echo count($c), "\n";
    $c = null;
    echo "c\n";
}
run();
echo "end\n";
