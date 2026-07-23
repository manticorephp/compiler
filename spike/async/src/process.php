<?php

namespace Async;

// Shared-nothing multi-process (the Zend/PHP scaling model): fork N workers, each
// running its OWN scheduler + reactor and binding the same port via SO_REUSEPORT
// (set in __mc_tcp_listen). The kernel load-balances accepts across the workers'
// independent accept queues — near-linear scaling, no locks, no shared state.

#[\Ffi\Library('c'), \Ffi\Symbol('fork')]
function sys_fork(): int {}

#[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
function sys_getpid(): int {}

/**
 * Fork into $n workers. Returns THIS worker's index: 0 for the original process,
 * 1..$n-1 for the forks. Call BEFORE run() — each worker then runs its own
 * scheduler and binds the shared port. A failed fork degrades to fewer workers.
 */
function workers(int $n): int
{
    for ($i = 1; $i < $n; $i++) {
        $pid = \Async\sys_fork();
        if ($pid === 0) {
            return $i;              // child → worker $i
        }
        if ($pid < 0) {
            return 0;               // fork failed — carry on with what we have
        }
    }
    return 0;                       // the original process = worker 0
}

/** This process's pid — handy for a per-worker log line. */
function pid(): int
{
    return \Async\sys_getpid();
}
