<?php
// A destructor that throws while a mixed (cell) slot drops its last reference:
// the throw must leave the function at the drop, both outside and inside a try.
class D { public function __construct(public string $n) {} public function __destruct() { throw new RuntimeException("dtor " . $this->n); } }
function mk(int $i, string $n): mixed { return $i > 0 ? new D($n) : $i; }

function overwrite(): void {
    $v = mk(1, "a");
    $v = mk(0, "x");
    echo "overwrite: after\n";
}
try { overwrite(); } catch (RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }

function inTry(): void {
    try {
        $v = mk(1, "b");
        unset($v);
        echo "inTry: after\n";
    } catch (RuntimeException $e) { echo "caught in ", $e->getMessage(), "\n"; }
    echo "inTry: done\n";
}
inTry();

function loop(): int {
    $s = 0;
    for ($i = 0; $i < 5; $i++) {
        $v = mk($i === 3 ? 1 : 0, "c" . $i);
        $s += $i;
    }
    return $s;
}
try { echo loop(), "\n"; } catch (RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }
echo "end\n";
