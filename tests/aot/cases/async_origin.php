<?php

// A parked task must report WHERE it was spawned, not just an fd/timer: the
// compiler folds `file:line` into the internal `…At` twin of every Async\ entry
// point that creates a task. `$g->spawn()` cannot be rewritten (the receiver's
// class is not known until InferTypes), so it inherits the scope's own line,
// flagged `near`.

use function Async\async;
use function Async\spawn;
use Async\TaskGroup;

async(function () {
    $t = spawn(function () { Async\delay(0.2); })->named('sleeper');
    $dump = Async\dump();
    echo strpos($dump, 'cases/async_origin.php:14') !== false ? "exact: yes\n" : "exact: NO\n";
    echo strpos($dump, '"sleeper"') !== false ? "named: yes\n" : "named: NO\n";
    $t->cancel();
    $t->join();

    TaskGroup::run(function (TaskGroup $g) {
        $c = $g->spawn(function () { Async\delay(0.2); });
        $d = Async\dump();
        echo strpos($d, 'near ') !== false && strpos($d, 'async_origin.php:21') !== false
            ? "near: yes\n" : "near: NO\n";
        $c->cancel();
        $c->join();
    });

    // The root task carries the async() site itself.
    echo strpos(Async\dump(), 'cases/async_origin.php:13') !== false ? "root: yes\n" : "root: NO\n";
});
