<?php

/**
 * Remaining filesystem std functions: process pipes (popen/pclose), symlink
 * owner ops (lchown/lchgrp), linkinfo, and the realpath-cache / file-buffer
 * introspection stubs. Global namespace so user code resolves here.
 */

/**
 * Open a pipe to a shell command. Returns a file resource, or false on failure.
 * MUST be closed with pclose (not fclose) so the child is reaped.
 * @return \Resource|false
 */
function popen(string $command, string $mode)
{
    $fp = \Runtime\Libc\popen($command, $mode);
    if ($fp === null) {
        return false;
    }
    return new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($fp));
}

/**
 * Close a pipe opened by popen and return the command's exit status (-1 on a
 * bad/closed handle). Reaps the child — fclose would leak a zombie.
 */
function pclose(\Resource $stream): int
{
    if ($stream->closed || $stream->addr === 0) {
        return -1;
    }
    $fp = \int_to_ptr($stream->addr);
    // Mark closed BEFORE the libc call so the resource's __destruct can't
    // fclose the already-pclosed FILE* (a double free / second wait).
    $stream->closed = true;
    $stream->addr = 0;
    $stream->type = 'Unknown';
    return \Runtime\Libc\pclose($fp);
}

/** Change the owner of a symlink itself (not its target). */
function lchown(string $filename, int $user): bool
{
    return \Runtime\Libc\sys_lchown($filename, $user, -1) === 0;
}

/** Change the group of a symlink itself (not its target). */
function lchgrp(string $filename, int $group): bool
{
    return \Runtime\Libc\sys_lchown($filename, -1, $group) === 0;
}

/**
 * The st_dev of a link (via lstat), or -1 if the path is not a valid link /
 * cannot be stat'd. php returns the device id; a nonexistent path is -1.
 * @return int
 */
function linkinfo(string $path): int
{
    $s = \lstat($path);
    if ($s === false) {
        return -1;
    }
    return (int)$s['dev'];
}

/**
 * Read (f_frsize, f_blocks, f_bavail) from a statvfs of $path, or false. The
 * struct layout is host-specific: Darwin's block counts are 32-bit at @16/@24,
 * glibc's are 64-bit at @16/@32; f_frsize is a 64-bit `unsigned long` at @8 on
 * both. Chosen at runtime ({@see __mc_host_is_darwin}), like the stat ABI.
 * @return array{0:int,1:int,2:int}|false
 */
function __mc_statvfs(string $path)
{
    $buf = \Runtime\Libc\calloc(256, 1);
    if ($buf === null) {
        return false;
    }
    $rc = \Runtime\Libc\sys_statvfs($path, $buf);
    if ($rc !== 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $frsize = \peek_i64($buf, 8);
    if (\__mc_host_is_darwin()) {
        $blocks = \peek_u32($buf, 16);
        $bavail = \peek_u32($buf, 24);
    } else {
        $blocks = \peek_i64($buf, 16);
        $bavail = \peek_i64($buf, 32);
    }
    \Runtime\Libc\free($buf);
    return [$frsize, $blocks, $bavail];
}

/**
 * Free space (bytes available to a non-root process) on the filesystem holding
 * $directory, as a float, or false on failure.
 * @return float|false
 */
function disk_free_space(string $directory)
{
    $v = \__mc_statvfs($directory);
    if ($v === false) {
        return false;
    }
    // Cast the tuple cells to plain ints first: (float)$cell*(float)$cell yields
    // a NUMERIC-CELL whose `> 0` (int) compares raw NaN bits (a compiler gap);
    // a clean int*int → float compares right.
    $bavail = (int)$v[2];
    $frsize = (int)$v[0];
    return (float)($bavail * $frsize);
}

/**
 * Total size (bytes) of the filesystem holding $directory, or false.
 * @return float|false
 */
function disk_total_space(string $directory)
{
    $v = \__mc_statvfs($directory);
    if ($v === false) {
        return false;
    }
    $blocks = (int)$v[1];
    $frsize = (int)$v[0];
    return (float)($blocks * $frsize);
}

/**
 * Alias of disk_free_space.
 * @return float|false
 */
function diskfreespace(string $directory)
{
    return \disk_free_space($directory);
}

/**
 * realpath cache introspection. Manticore keeps no realpath cache, so the cache
 * is always empty — the truthful answer, and what a fresh php process reports
 * before any realpath() call.
 * @return array<string,mixed>
 */
function realpath_cache_get(): array
{
    return [];
}

/** Size in bytes of the (empty) realpath cache. */
function realpath_cache_size(): int
{
    return 0;
}

/**
 * Set the write buffer size for a stream (alias stream_set_write_buffer).
 * Buffering is transparent here (a no-op); php's own stdio-backed streams
 * report -1 for this call, so return -1 to match the oracle exactly.
 * @param \Resource $stream
 */
function set_file_buffer(\Resource $stream, int $size): int
{
    return -1;
}

/**
 * Run a command through the shell and return its stdout.
 *
 * Built on the popen/pclose pair above, so this is a REAL implementation and
 * not a stub: symfony/console shells out to `stty` to size the terminal and to
 * read a hidden password, and both need the output. php returns null when the
 * command produced nothing, and false only when the pipe could not be opened.
 */
function shell_exec(string $command): mixed
{
    $fp = \Runtime\Libc\popen($command, "r");
    if (\ptr_to_int($fp) === 0) { return false; }
    $out = \__mc_drain_pipe($fp);
    \Runtime\Libc\pclose($fp);
    if ($out === "") { return null; }
    return $out;
}

/** Read a FILE* to EOF. Shared by shell_exec / exec / system / passthru. */
function __mc_drain_pipe(\Ffi\Ptr $fp): string
{
    $cap = 8192;
    $buf = \Runtime\Libc\malloc($cap);
    $out = "";
    while (true) {
        $n = \Runtime\Libc\fread($buf, 1, $cap, $fp);
        if ($n <= 0) { break; }
        $i = 0;
        while ($i < $n) {
            $out = $out . \chr(\peek_u8($buf, $i));
            $i = $i + 1;
        }
        if ($n < $cap) { break; }
    }
    \Runtime\Libc\free($buf);
    return $out;
}

/**
 * php's exec(): the LAST line of output is returned, every line is appended to
 * `$output`, and the exit status lands in `$result_code`.
 */
function exec(string $command, #[\Manticore\Attr\RefOut] array &$output = [], #[\Manticore\Attr\RefOut] int &$result_code = 0): mixed
{
    $fp = \Runtime\Libc\popen($command, "r");
    if (\ptr_to_int($fp) === 0) { return false; }
    $raw = \__mc_drain_pipe($fp);
    $status = \Runtime\Libc\pclose($fp);
    $result_code = $status >= 0 ? \intdiv($status, 256) : $status;
    $last = "";
    $lines = \explode("\n", \rtrim($raw, "\n"));
    foreach ($lines as $l) {
        if ($raw === "") { break; }
        $output[] = $l;
        $last = $l;
    }
    return $last;
}

/** php's system(): output goes straight to stdout, the last line is returned. */
function system(string $command, #[\Manticore\Attr\RefOut] int &$result_code = 0): mixed
{
    $fp = \Runtime\Libc\popen($command, "r");
    if (\ptr_to_int($fp) === 0) { return false; }
    $raw = \__mc_drain_pipe($fp);
    $status = \Runtime\Libc\pclose($fp);
    $result_code = $status >= 0 ? \intdiv($status, 256) : $status;
    echo $raw;
    $trimmed = \rtrim($raw, "\n");
    $nl = \strrpos($trimmed, "\n");
    return $nl === false ? $trimmed : \substr($trimmed, $nl + 1);
}

/** php's passthru(): raw output to stdout, no return value. */
function passthru(string $command, #[\Manticore\Attr\RefOut] int &$result_code = 0): mixed
{
    $fp = \Runtime\Libc\popen($command, "r");
    if (\ptr_to_int($fp) === 0) { return false; }
    $raw = \__mc_drain_pipe($fp);
    $status = \Runtime\Libc\pclose($fp);
    $result_code = $status >= 0 ? \intdiv($status, 256) : $status;
    echo $raw;
    return null;
}
