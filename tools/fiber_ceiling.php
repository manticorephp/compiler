<?php
// How many concurrent tasks fit in one process, and what runs out first.
//
// A fiber stack is virtual address space, not memory: pages are touched lazily,
// so RSS stays small. What it really spends is ONE MAPPING per live fiber, and on
// Linux `vm.max_map_count` (65530 by default) is a hard per-process ceiling that
// arrives long before the address space does. "Many concurrent connections" is the
// whole premise of the runtime, so the number should be measured rather than
// assumed.
//
//   bin/manticore compile tools/fiber_ceiling.php -o /tmp/fc
//   /tmp/fc [stack_bytes] [max_tasks]
//
// Each task parks on a channel nobody ever sends to, so every one of them holds
// its stack for the whole run. The walk stops on the FiberError that
// Fiber::start() now raises when a stack cannot be mapped — before that fix this
// program could only crash, which is exactly why the ceiling was never measured.

$stack = ($argc > 1) ? (int)$argv[1] : 8388608;
$max   = ($argc > 2) ? (int)$argv[2] : 200000;
$step  = 1000;

\Fiber::setStackSize($stack);
$stack = \Fiber::stackSize();
echo "stack: ", $stack, " bytes (", \number_format($stack / 1048576, 2), " MiB)\n";
echo "host:  ", \PHP_OS, " ", \php_uname('m'), "\n";
if (($mmc = @\file_get_contents('/proc/sys/vm/max_map_count')) !== false) {
    echo "vm.max_map_count: ", \trim((string)$mmc), "\n";
}

\Async\async(function () use ($stack, $max, $step) {
    \Async\group(function (\Async\TaskGroup $g) use ($stack, $max, $step) {
        $gate = new \Async\Channel(0);
        $live = 0;
        $why = 'reached the requested maximum';
        try {
            while ($live < $max) {
                for ($i = 0; $i < $step; $i++) {
                    $g->spawn(function () use ($gate) { $gate->recv(); });
                    $live++;
                }
                // Let every task reach its first suspend point, so `live` counts
                // stacks that actually exist rather than closures queued to start.
                \Async\sleep(0.0);
                report($live, $stack);
            }
        } catch (\FiberError $e) {
            $why = 'FiberError: ' . $e->getMessage();
        } catch (\Throwable $e) {
            $why = \get_class($e) . ': ' . $e->getMessage();
        }
        echo "\nceiling: ", $live, " concurrent tasks\n";
        echo "stopped: ", $why, "\n";
        report($live, $stack);
        // Every task is parked on a channel nobody sends to — that is the point,
        // and the scope would otherwise never close. Cancelling releases them all.
        $g->cancel();
    });
});

function report(int $live, int $stack): void
{
    $line = '  live=' . $live
        . ' va~' . \number_format($live * $stack / 1073741824, 1) . 'GiB';
    // /proc is the only precise answer, and it is Linux-only. On Darwin the shell
    // driver samples `ps -o rss=,vsz=` instead — a program cannot ask about itself.
    $st = @\file_get_contents('/proc/self/status');
    if ($st !== false) {
        foreach (['VmSize', 'VmPeak', 'VmRSS'] as $k) {
            if (\preg_match('/^' . $k . ':\s*(\d+) kB/m', (string)$st, $m) === 1) {
                $line .= ' ' . $k . '=' . \number_format((int)$m[1] / 1048576, 2) . 'GiB';
            }
        }
        $maps = @\file_get_contents('/proc/self/maps');
        if ($maps !== false) {
            $line .= ' maps=' . (string)\count(\explode("\n", \rtrim((string)$maps, "\n")));
        }
    }
    echo $line, "\n";
}
