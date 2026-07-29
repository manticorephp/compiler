<?php
// A failure that escapes async() names the task that raised it.
//
// The exception itself cannot: it is rethrown by whoever joins the task, so it
// arrives carrying the JOINER's file and line, and the task that actually failed
// appears nowhere in the trace. Manticore-only (Async\failure is superset), so
// nothing may be printed before the first Async\ call.

try {
    \Async\async(function () {
        \Async\group(function (\Async\TaskGroup $g) {
            $g->spawn(function () {
                \Async\delay(0.001);
                throw new \RuntimeException('no such row');
            });
            $g->spawn(function () { \Async\delay(10.0); });
        });
    });
    echo "unreachable\n";
} catch (\RuntimeException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}

$where = \Async\failure();
// The spawn site is folded in by the compiler, so it names THIS file and the
// line the throwing task was started on — not the line that joined it.
echo "names the task: ", \str_contains($where, 'raised RuntimeException: no such row') ? 'yes' : 'no', "\n";
echo "names the file: ", \str_contains($where, 'async_failure_origin.php') ? 'yes' : 'no', "\n";
echo "names a scope:  ", \str_contains($where, 'in scope') ? 'yes' : 'no', "\n";

// A second run must not inherit the first one's provenance.
\Async\async(function () { \Async\delay(0.001); });
echo "cleared: ", \Async\failure() === '' ? 'yes' : 'no', "\n";

// A cancelled sibling is not a failure and must never be named.
try {
    \Async\async(function () {
        \Async\group(function (\Async\TaskGroup $g) {
            $g->spawn(function () { throw new \LogicException('first'); });
            $g->spawn(function () { \Async\delay(10.0); });
        });
    });
} catch (\LogicException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo "blames the raiser: ", \str_contains(\Async\failure(), 'LogicException: first') ? 'yes' : 'no', "\n";
echo "not the sibling:   ", \str_contains(\Async\failure(), 'Cancelled') ? 'no' : 'yes', "\n";
