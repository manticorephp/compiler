<?php
// A worker that returns exits cleanly (0); the supervisor must not restart a
// clean exit, and supervise() must return once every worker is gone. Before
// the T3 fix, any non-restart branch was untested — a bug that restarted a
// clean exit would spin forever here (a real infinite loop, not a flake).

$start = microtime(true);
\Process\supervise(2, function (int $i): void {
    // returns immediately -> exit(0) in Supervisor::start()
});
$elapsed = microtime(true) - $start;

echo "returned\n";
echo $elapsed < 2.0 ? "fast\n" : "slow\n";
