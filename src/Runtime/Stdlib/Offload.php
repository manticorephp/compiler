<?php

/**
 * Blocking-offload pool. The codegen builtin of the same name starts one native
 * worker thread (IR, EmitLlvmModule::offloadRuntime) — this body is what a compiler
 * that predates the builtin links, and "-1" makes every caller run inline.
 */
function __mc_pool_start(int $submitFd, int $doneFd): int
{
    return -1;
}

// Op codes: a mirror of MemoryAbi::OFFLOAD_OP_* — keep identical.
const __MC_OFF_NOP = 0;
const __MC_OFF_FOPEN = 1;
const __MC_OFF_FREAD = 2;
const __MC_OFF_FWRITE = 3;
const __MC_OFF_FFLUSH = 4;
const __MC_OFF_FCLOSE = 5;
const __MC_OFF_FSYNC = 6;
const __MC_OFF_STAT = 7;
const __MC_OFF_LSTAT = 8;
const __MC_OFF_OPENDIR = 9;
const __MC_OFF_READDIR = 10;
const __MC_OFF_CLOSEDIR = 11;
const __MC_OFF_UNLINK = 12;
const __MC_OFF_RENAME = 13;
const __MC_OFF_MKDIR = 14;
const __MC_OFF_RMDIR = 15;
const __MC_OFF_GETADDRINFO = 16;
const __MC_OFF_OPEN = 17;

/** True when a covered call should go through {@see __mc_offload()}. */
function __mc_offload_active(): bool
{
    return \Runtime\AsyncHook::active() && \Runtime\AsyncHook::blocker() !== null;
}

/**
 * Run one covered libc call on the pool and park the calling task until it is
 * done. The result is the C return widened to int (pointers as addresses);
 * errno via {@see __mc_offload_errno()}. With no scheduler it runs inline.
 */
function __mc_offload(int $op, int $a0 = 0, int $a1 = 0, int $a2 = 0, int $a3 = 0, int $a4 = 0): int
{
    $f = \Runtime\AsyncHook::blocker();
    if ($f === null) {
        return \__mc_offload_inline($op, $a0, $a1, $a2, $a3, $a4);
    }
    return $f($op, $a0, $a1, $a2, $a3, $a4);
}

function __mc_offload_errno(): int
{
    return \Runtime\AsyncHook::offloadErrno();
}

/**
 * The synchronous twin of the pool worker: the same call per op, the same
 * argument slots, the same widened result. Taken when the pool cannot start.
 */
function __mc_offload_inline(int $op, int $a0 = 0, int $a1 = 0, int $a2 = 0, int $a3 = 0, int $a4 = 0): int
{
    $ret = match ($op) {
        __MC_OFF_NOP => $a0,
        __MC_OFF_FOPEN => \ptr_to_int(\Runtime\Libc\fopen(
            \cstr_to_str(\int_to_ptr($a0)), \cstr_to_str(\int_to_ptr($a1)))),
        __MC_OFF_FREAD => \Runtime\Libc\fread(\int_to_ptr($a0), 1, $a1, \int_to_ptr($a2)),
        __MC_OFF_FWRITE => \Runtime\Libc\fwrite_buf(\int_to_ptr($a0), 1, $a1, \int_to_ptr($a2)),
        __MC_OFF_FFLUSH => \Runtime\Libc\fflush(\int_to_ptr($a0)),
        __MC_OFF_FCLOSE => \Runtime\Libc\fclose(\int_to_ptr($a0)),
        __MC_OFF_FSYNC => \Runtime\Libc\sys_fsync($a0),
        __MC_OFF_STAT => \Runtime\Libc\sys_stat(\cstr_to_str(\int_to_ptr($a0)), \int_to_ptr($a1)),
        __MC_OFF_LSTAT => \Runtime\Libc\sys_lstat(\cstr_to_str(\int_to_ptr($a0)), \int_to_ptr($a1)),
        __MC_OFF_OPENDIR => \ptr_to_int(\Runtime\Libc\sys_opendir(\cstr_to_str(\int_to_ptr($a0)))),
        __MC_OFF_READDIR => \ptr_to_int(\Runtime\Libc\sys_readdir(\int_to_ptr($a0))),
        __MC_OFF_CLOSEDIR => \Runtime\Libc\sys_closedir(\int_to_ptr($a0)),
        __MC_OFF_UNLINK => \Runtime\Libc\sys_unlink(\cstr_to_str(\int_to_ptr($a0))),
        __MC_OFF_RENAME => \Runtime\Libc\sys_rename(
            \cstr_to_str(\int_to_ptr($a0)), \cstr_to_str(\int_to_ptr($a1))),
        __MC_OFF_MKDIR => \Runtime\Libc\sys_mkdir(\cstr_to_str(\int_to_ptr($a0)), $a1),
        __MC_OFF_RMDIR => \Runtime\Libc\sys_rmdir(\cstr_to_str(\int_to_ptr($a0))),
        __MC_OFF_GETADDRINFO => \Runtime\Libc\sys_getaddrinfo_ptr(
            \int_to_ptr($a0), \int_to_ptr($a1), \int_to_ptr($a2), \int_to_ptr($a3)),
        __MC_OFF_OPEN => \Runtime\Libc\sys_open(\cstr_to_str(\int_to_ptr($a0)), $a1, $a2),
        default => -1,
    };
    \Runtime\AsyncHook::setOffloadErrno(\__mc_errno());
    return $ret;
}

function posix_mkfifo(string $filename, int $permissions): bool
{
    return \Runtime\Libc\sys_mkfifo($filename, $permissions) === 0;
}

/**
 * {@see __mc_offload()} for an op whose first argument is a path: the pool gets a
 * C copy of the string, freed once the job is done — never the PHP string itself.
 */
function __mc_offload_path(int $op, string $path, int $a1 = 0): int
{
    $c = \Runtime\Libc\strdup($path);
    $r = \__mc_offload($op, \ptr_to_int($c), $a1);
    \Runtime\Libc\free($c);
    return $r;
}

/** {@see __mc_offload_path()} for an op taking two strings (fopen, rename). */
function __mc_offload_path2(int $op, string $a, string $b): int
{
    $ca = \Runtime\Libc\strdup($a);
    $cb = \Runtime\Libc\strdup($b);
    $r = \__mc_offload($op, \ptr_to_int($ca), \ptr_to_int($cb));
    \Runtime\Libc\free($ca);
    \Runtime\Libc\free($cb);
    return $r;
}

/** A regular file or directory handle opened on a path: its calls may go to the pool. */
function __mc_pooled_res(int $kind, int $addr): \Resource
{
    $r = new \Resource($kind, 'stream', $addr);
    $r->pooled = true;
    return $r;
}

/** Whether a call on $r's handle goes to the pool now. */
function __mc_res_pooled(\Resource $r): bool
{
    return $r->pooled && !$r->closed && $r->addr !== 0 && \__mc_offload_active();
}

function __mc_res_busy(\Resource $r): void
{
    $r->poolJobs = $r->poolJobs + 1;
}

function __mc_res_done(\Resource $r): void
{
    $r->poolJobs = $r->poolJobs - 1;
    if ($r->poolJobs === 0) {
        $w = \Runtime\AsyncHook::idleWaker();
        if ($w !== null) {
            $w($r);
        }
    }
}

/** {@see __mc_offload()} on $r's handle, counted so a close can wait for it. */
function __mc_res_offload(\Resource $r, int $op, int $a0 = 0, int $a1 = 0, int $a2 = 0): int
{
    \__mc_res_busy($r);
    $n = \__mc_offload($op, $a0, $a1, $a2);
    \__mc_res_done($r);
    return $n;
}

/**
 * Close $r's handle on the pool. The resource is retired first, so no new job
 * takes the handle, then the close waits until no sibling's job still uses it.
 */
function __mc_res_offload_close(\Resource $r, int $op): bool
{
    $addr = $r->addr;
    $r->closed = true;
    $r->addr = 0;
    $r->type = 'Unknown';
    if ($r->poolJobs > 0) {
        $w = \Runtime\AsyncHook::idleWaiter();
        if ($w !== null) {
            $w($r);
        }
    }
    return \__mc_offload($op, $addr) === 0;
}

/** fwrite(3) of $len bytes of $data to the FILE* at $fp, from a private copy. */
function __mc_offload_fwrite(string $data, int $len, int $fp): int
{
    if ($len <= 0) {
        return 0;
    }
    $copy = \Runtime\Libc\malloc($len);
    if ($copy === null) {
        return 0;
    }
    \Runtime\Libc\memcpy($copy, \int_to_ptr(\str_bytes($data)), $len);
    $n = \__mc_offload(__MC_OFF_FWRITE, \ptr_to_int($copy), $len, $fp);
    \Runtime\Libc\free($copy);
    return $n < 0 ? 0 : $n;
}
