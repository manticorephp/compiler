<?php
// The open scope belongs to the TASK, not to a scheduler-global stack: two
// tasks each opening their own nested TaskGroup, interleaved, must not adopt
// each other's children. And a second async() must start from a clean engine.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use Async\TaskGroup;

$mk = function (string $tag, float $d) {
    return function () use ($tag, $d) {
        return TaskGroup::run(function (TaskGroup $g) use ($tag, $d) {
            $c = $g->spawn(function () use ($d) { delay($d); return 1; });
            return $tag . ":" . $c->await();
        });
    };
};

async(function () use ($mk) {
    $p = spawn($mk("A", 0.03));
    $q = spawn($mk("B", 0.01));
    echo "nested: ", $p->await(), " ", $q->await(), "\n";
});

async(function () {
    $t = spawn(fn() => "second");
    echo "run2: ", $t->await(), "\n";
});

// A task parked with nothing left to wake it is a DEADLOCK, not a silent rc=0.
try {
    async(function () {
        $ch = \Async\channel();
        $ch->recv();
    });
    echo "deadlock: MISSING\n";
} catch (\Async\DeadlockException $e) {
    echo "deadlock: detected\n";
}
echo "done\n";
