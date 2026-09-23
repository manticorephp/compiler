<?php

/**
 * proc_open / proc_close / proc_get_status / proc_terminate.
 *
 * A pipe per descriptor, `fork`, `dup2` onto the child's 0/1/2, then
 * `execv("/bin/sh", ["sh", "-c", $command])` — php's own shape for a STRING
 * command. php 7.4's ARRAY command form is not supported: it execs the program
 * directly with no shell, which is a different (and safer) contract, and the
 * front end refuses `vec[string]` here rather than silently shelling out.
 *
 * The parent wraps its ends with `fdopen` so the whole `f*` / `stream_*` family
 * reads them unchanged, and the handle it returns is a `\Resource` carrying the
 * child's pid.
 *
 * ⚠ Every fd the child does not need is closed in the child, and every child
 * end is closed in the parent. A write end left open in either is why `cat`
 * would never see EOF — that is what the second spawn in
 * `tests/aot/cases/proc_open_pipes.php` checks.
 */

use Manticore\Attr\RefOut;

/** How many descriptors proc_open() wires. php's own limit is the spec's size;
 *  three covers stdin/stdout/stderr, which is what a shell command uses. */
const __MC_PROC_MAX_FD = 3;

/**
 * A string command runs through `/bin/sh -c`; an ARRAY command is the argv
 * itself, executed directly with a PATH search and no shell, as php does.
 *
 * @param string|array<int, mixed> $command
 * @param array<int, mixed> $descriptor_spec
 * @param array<int, mixed> $pipes
 * @param array<string, string>|null $env
 * @param array<string, mixed>|null $options
 */
function proc_open(
    array|string $command,
    array $descriptor_spec,
    #[RefOut] array &$pipes = [],
    ?string $cwd = null,
    ?array $env = null,
    ?array $options = null,
): mixed {
    $pipes = [];
    /** @var string[] $argvList */
    $argvList = [];
    if (\is_array($command)) {
        foreach ($command as $part) { $argvList[] = (string)$part; }
        if ($argvList === []) {
            throw new \ValueError('proc_open(): Argument #1 ($command) must have at least one element');
        }
    }

    // Per descriptor: the fd the CHILD gets, the fd the PARENT keeps, and the
    // mode the parent's stdio wrapper opens with. -1 means "not wired".
    $childFd = [-1, -1, -1];
    $parentFd = [-1, -1, -1];
    $parentMode = ['', '', ''];

    for ($i = 0; $i < __MC_PROC_MAX_FD; $i++) {
        if (!isset($descriptor_spec[$i])) {
            continue;
        }
        $d = $descriptor_spec[$i];
        if (!\is_array($d) || \count($d) === 0) {
            continue;
        }
        $what = (string)$d[0];
        if ($what !== 'pipe') {
            // 'file' and an inherited fd are not wired yet; the child then keeps
            // this process's own descriptor, which is php's behaviour for a
            // spec entry it cannot honour either.
            continue;
        }
        $box = \Runtime\Libc\calloc(2, 4);
        if (\Runtime\Libc\sys_pipe($box) !== 0) {
            \Runtime\Libc\free($box);
            __mc_proc_cleanup($childFd, $parentFd);
            return false;
        }
        $rd = \peek_i32($box, 0);
        $wr = \peek_i32($box, 4);
        \Runtime\Libc\free($box);
        // `r` is what the CHILD reads, so the child keeps the read end and the
        // parent writes; `w` is the mirror. php names the mode from the CHILD's
        // point of view and hands the parent the other end.
        if ((string)($d[1] ?? 'r') === 'r') {
            $childFd[$i] = $rd;
            $parentFd[$i] = $wr;
            $parentMode[$i] = 'w';
        } else {
            $childFd[$i] = $wr;
            $parentFd[$i] = $rd;
            $parentMode[$i] = 'r';
        }
    }

    $pid = \Runtime\Libc\sys_fork();
    if ($pid < 0) {
        __mc_proc_cleanup($childFd, $parentFd);
        return false;
    }
    if ($pid === 0) {
        // CHILD. Put each wired end on its standard descriptor, then drop every
        // fd this process should not carry into the new image.
        for ($i = 0; $i < __MC_PROC_MAX_FD; $i++) {
            if ($childFd[$i] >= 0) {
                \Runtime\Libc\sys_dup2($childFd[$i], $i);
            }
        }
        for ($i = 0; $i < __MC_PROC_MAX_FD; $i++) {
            if ($childFd[$i] >= $i) {
                \Runtime\Libc\sys_close($childFd[$i]);
            }
            if ($parentFd[$i] >= 0) {
                \Runtime\Libc\sys_close($parentFd[$i]);
            }
        }
        if ($cwd !== null && $cwd !== '') {
            \Runtime\Libc\sys_chdir($cwd);
        }
        if ($argvList !== []) {
            $na = \count($argvList);
            $argv = \Runtime\Libc\calloc($na + 1, 8);
            for ($k = 0; $k < $na; $k++) {
                \poke_i64($argv, $k * 8, \ptr_to_int(\Runtime\Libc\strdup($argvList[$k])));
            }
            \poke_i64($argv, $na * 8, 0);
            \Runtime\Libc\sys_execvp($argvList[0], $argv);
        } else {
            $argv = \Runtime\Libc\calloc(4, 8);
            \poke_i64($argv, 0, \ptr_to_int(\Runtime\Libc\strdup('sh')));
            \poke_i64($argv, 8, \ptr_to_int(\Runtime\Libc\strdup('-c')));
            \poke_i64($argv, 16, \ptr_to_int(\Runtime\Libc\strdup((string)$command)));
            \poke_i64($argv, 24, 0);
            \Runtime\Libc\sys_execv('/bin/sh', $argv);
        }
        // Only reached when exec failed. 127 is what a shell reports for a
        // command it could not run, and _exit skips the atexit handlers this
        // child shares with its parent.
        \Runtime\Libc\sys_exit_raw(127);
    }

    // PARENT. The child's ends are the child's now.
    for ($i = 0; $i < __MC_PROC_MAX_FD; $i++) {
        if ($childFd[$i] >= 0) {
            \Runtime\Libc\sys_close($childFd[$i]);
        }
        if ($parentFd[$i] >= 0) {
            $fp = \Runtime\Libc\sys_fdopen($parentFd[$i], $parentMode[$i]);
            if ($fp === null) {
                \Runtime\Libc\sys_close($parentFd[$i]);
                continue;
            }
            $pipes[$i] = new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($fp));
        }
    }
    return new \Resource(\Resource::KIND_PROCESS, 'process', $pid);
}

/** Close whatever was opened before a failure, so a refused spawn leaks no fd. */
function __mc_proc_cleanup(array<int, int> $childFd, array<int, int> $parentFd): void
{
    for ($i = 0; $i < __MC_PROC_MAX_FD; $i++) {
        if ($childFd[$i] >= 0) {
            \Runtime\Libc\sys_close($childFd[$i]);
        }
        if ($parentFd[$i] >= 0) {
            \Runtime\Libc\sys_close($parentFd[$i]);
        }
    }
}

/**
 * Wait for the child and answer its exit status. php closes any pipe the caller
 * left open first; a pipe still open would keep a child blocked on a write.
 */
function proc_close(mixed $process): int
{
    if (!($process instanceof \Resource) || $process->type !== 'process') {
        return -1;
    }
    $pid = $process->addr;
    if ($pid <= 0) {
        return -1;
    }
    $box = \Runtime\Libc\calloc(1, 8);
    $got = \Runtime\Libc\sys_waitpid($pid, $box, 0);
    $status = \peek_i32($box, 0);
    \Runtime\Libc\free($box);
    if ($got !== $pid) {
        return -1;
    }
    return __mc_proc_exitcode($status);
}

/** php's exit status: the code for a normal exit, 128+signo for a signalled one. */
function __mc_proc_exitcode(int $status): int
{
    if (($status & 127) === 0) {
        return ($status >> 8) & 255;
    }
    return 128 + ($status & 127);
}

/** @return array<string, mixed> */
function proc_get_status(mixed $process): array
{
    if (!($process instanceof \Resource) || $process->type !== 'process') {
        return [
            'command' => '',
            'pid' => 0,
            'running' => false,
            'signaled' => false,
            'stopped' => false,
            'exitcode' => -1,
            'termsig' => 0,
            'stopsig' => 0,
        ];
    }
    $pid = $process->addr;
    $box = \Runtime\Libc\calloc(1, 8);
    // WNOHANG: 1 on both hosts.
    $got = \Runtime\Libc\sys_waitpid($pid, $box, 1);
    $status = \peek_i32($box, 0);
    \Runtime\Libc\free($box);
    $running = $got === 0;
    $signaled = !$running && ($status & 127) !== 0 && ($status & 127) !== 127;
    return [
        'command' => '',
        'pid' => $pid,
        'running' => $running,
        'signaled' => $signaled,
        'stopped' => false,
        'exitcode' => $running ? -1 : __mc_proc_exitcode($status),
        'termsig' => $signaled ? ($status & 127) : 0,
        'stopsig' => 0,
    ];
}

function proc_terminate(mixed $process, int $signal = 15): bool
{
    if (!($process instanceof \Resource) || $process->type !== 'process') {
        return false;
    }
    $pid = $process->addr;
    if ($pid <= 0) {
        return false;
    }
    return \Runtime\Libc\sys_kill($pid, $signal) === 0;
}

function proc_nice(int $priority): bool
{
    return false;
}
