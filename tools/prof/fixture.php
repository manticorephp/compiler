<?php
/**
 * Self-test input for tools/prof/live.sh — a program whose live set has a KNOWN
 * owner and a known size.
 *
 * `fixtureAllocs()` builds N objects and the array holding them, and NOTHING
 * releases them before the sleep, so any snapshot taken during the sleep must
 * charge ~N blocks to `fixtureAllocs`. A report that instead names
 * `__mir_alloc_tagged` (or `__main`) is a broken report, not a finding — that
 * is exactly what this fixture is for.
 *
 * Two call sites, deliberately: with one, clang -O2 may inline the whole body
 * into `__main` and the symbol the profiler needs would not exist.
 *
 *   ./bin/manticore.nopool compile tools/prof/fixture.php -o /tmp/fixture
 *   BIN=/tmp/fixture bash tools/prof/live.sh -- 400000
 */

class FixtureNode
{
    public int $a;
    public FixtureNode|null $next;

    public function __construct(int $a)
    {
        $this->a = $a;
        $this->next = null;
    }
}

/** @return FixtureNode[] */
function fixtureAllocs(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = new FixtureNode($i);
    }
    return $out;
}

$n = 400000;
if ($argc > 1) { $n = (int)$argv[1]; }

$a = fixtureAllocs($n);
$b = fixtureAllocs(intdiv($n, 4));

echo "fixture: live ", count($a) + count($b), " objects, holding\n";
sleep(20);
echo "fixture: done ", $a[0]->a + $b[0]->a, "\n";
