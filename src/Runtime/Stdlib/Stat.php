<?php

/**
 * php.net's stat family and directory iteration.
 *
 * These are the only stdlib functions that read C structs, and `struct stat`
 * has a different layout on every target: field offsets AND widths move between
 * Darwin and Linux, and again between Linux/x86_64 and Linux/arm64. The offsets
 * cannot be resolved at compile time — the stdlib is compiled by the Zend seed,
 * where the libc bindings are empty stubs, so a compile-time host_os() would
 * kill the cold bootstrap. They are therefore selected at RUNTIME from uname(2)
 * and cached.
 *
 * A wrong table would not crash; it would silently return garbage. So the table
 * is self-checked once against a fact no layout can fake — stat("/") must come
 * back as a directory with >= 2 links — and a mismatch throws instead.
 */

/**
 * One `struct stat` field offset for the running host, by index:
 *   0 mode  1 mode_w  2 nlink  3 nlink_w  4 ino  5 uid
 *   6 gid   7 atime   8 mtime  9 ctime   10 size 11 bufsz
 * (_w suffixes are the member's width in bytes, not an offset.)
 *
 * The table is deliberately NOT returned as an array. An assoc BUILT INSIDE the
 * stdlib module breaks once it crosses a call boundary — the second call
 * through a cached `static` array faults, and a runtime string key on one
 * silently misses. Int statics carry no refcount and dodge both. Same layout
 * data, just never handed out as a container.
 */
function __mc_stat_off(int $which): int
{
    static $ready = 0;
    static $mode = 0;
    static $modeW = 0;
    static $nlink = 0;
    static $nlinkW = 0;
    static $ino = 0;
    static $uid = 0;
    static $gid = 0;
    static $atime = 0;
    static $mtime = 0;
    static $ctime = 0;
    static $size = 0;
    static $bufsz = 0;

    if ($ready === 0) {
        // sysname is at offset 0 of struct utsname on both hosts; `machine` is
        // not — Darwin's members are char[256], glibc's are char[65]. So the OS
        // must be read first, and it picks the stride for the arch.
        $ub = \Runtime\Libc\calloc(2048, 1);
        if ($ub === null) {
            throw new \RuntimeException('stat: cannot allocate a uname buffer');
        }
        \Runtime\Libc\uname($ub);
        $sys = \cstr_to_str($ub);
        $isDarwin = \substr($sys, 0, 6) === 'Darwin';
        $machine = \cstr_to_str(\ptr_offset($ub, $isDarwin ? 1024 : 260));
        \Runtime\Libc\free($ub);
        $isArm = \substr($machine, 0, 3) === 'arm' || \substr($machine, 0, 7) === 'aarch64';

        if ($isDarwin) {
            // Darwin (arm64 and x86_64 share the 64-bit-inode layout):
            // dev@0 mode@4:u16 nlink@6:u16 ino@8 uid@16 gid@20 rdev@24 [pad]
            // atimespec@32 mtimespec@48 ctimespec@64 birthtimespec@80 size@96
            $mode = 4;  $modeW = 2;  $nlink = 6;  $nlinkW = 2;
            $ino = 8;   $uid = 16;  $gid = 20;
            $atime = 32; $mtime = 48; $ctime = 64;
            $size = 96; $bufsz = 144;
        } elseif ($isArm) {
            // Linux/arm64 (asm-generic/stat.h):
            // dev@0 ino@8 mode@16:u32 nlink@20:u32 uid@24 gid@28 rdev@32 pad@40
            // size@48 blksize@56 blocks@64 atime@72 mtime@88 ctime@104
            $mode = 16; $modeW = 4;  $nlink = 20; $nlinkW = 4;
            $ino = 8;   $uid = 24;  $gid = 28;
            $atime = 72; $mtime = 88; $ctime = 104;
            $size = 48; $bufsz = 128;
        } else {
            // Linux/x86_64 (glibc bits/stat.h):
            // dev@0 ino@8 nlink@16 mode@24:u32 uid@28 gid@32 pad@36 rdev@40
            // size@48 blksize@56 blocks@64 atim@72 mtim@88 ctim@104
            $mode = 24; $modeW = 4;  $nlink = 16; $nlinkW = 8;
            $ino = 8;   $uid = 28;  $gid = 32;
            $atime = 72; $mtime = 88; $ctime = 104;
            $size = 48; $bufsz = 144;
        }

        // Verify the table CHOICE, not every field: "/" is a directory with at
        // least two links (. and ..) on every POSIX host. A mis-picked table
        // reads those offsets as unrelated bytes and will not satisfy both.
        $probe = \Runtime\Libc\calloc($bufsz + 64, 1);
        if ($probe === null) {
            throw new \RuntimeException('stat: cannot allocate a probe buffer');
        }
        if (\Runtime\Libc\sys_stat('/', $probe) !== 0) {
            \Runtime\Libc\free($probe);
            throw new \RuntimeException('stat: cannot stat "/" to verify the ABI');
        }
        $pm = $modeW === 2 ? \peek_u16($probe, $mode) : \peek_u32($probe, $mode);
        $pn = $nlinkW === 2
            ? \peek_u16($probe, $nlink)
            : ($nlinkW === 4 ? \peek_u32($probe, $nlink) : \peek_i64($probe, $nlink));
        \Runtime\Libc\free($probe);
        // S_IFMT = 0170000, S_IFDIR = 0040000.
        if (($pm & 0170000) !== 0040000 || $pn < 2) {
            throw new \RuntimeException(
                'stat: struct stat layout not recognised for ' . $sys . '/' . $machine
                . ' (stat("/") gave mode=' . \decoct($pm) . ' nlink=' . $pn . ')'
            );
        }
        $ready = 1;
    }

    if ($which === 0)  { return $mode; }
    if ($which === 1)  { return $modeW; }
    if ($which === 2)  { return $nlink; }
    if ($which === 3)  { return $nlinkW; }
    if ($which === 4)  { return $ino; }
    if ($which === 5)  { return $uid; }
    if ($which === 6)  { return $gid; }
    if ($which === 7)  { return $atime; }
    if ($which === 8)  { return $mtime; }
    if ($which === 9)  { return $ctime; }
    if ($which === 10) { return $size; }
    return $bufsz;
}

/**
 * stat(2) $path into $buf (caller-allocated, at least __mc_stat_off(11) bytes).
 * Returns whether it succeeded.
 *
 * The buffer is an out-param rather than a `?\Ffi\Ptr` return: a null-or-object
 * union types as non-null and leaves the slot un-zeroed under the native
 * self-build, so `=== null` on the result is not reliable. Io.php dodges the
 * same thing by giving fopen a plain `Ptr` return whose failure is address 0.
 */
function __mc_stat_into(string $path, \Ffi\Ptr $buf, bool $link = false): bool
{
    $rc = $link
        ? \Runtime\Libc\sys_lstat($path, $buf)
        : \Runtime\Libc\sys_stat($path, $buf);
    return $rc === 0;
}

/** st_mode of $path, or -1 when it cannot be stat'ed. */
function __mc_stat_mode(string $path, bool $link = false): int
{
    $buf = \Runtime\Libc\calloc(\__mc_stat_off(11) + 64, 1);
    if ($buf === null) {
        return -1;
    }
    if (!\__mc_stat_into($path, $buf, $link)) {
        \Runtime\Libc\free($buf);
        return -1;
    }
    $off = \__mc_stat_off(0);
    $mode = \__mc_stat_off(1) === 2 ? \peek_u16($buf, $off) : \peek_u32($buf, $off);
    \Runtime\Libc\free($buf);
    return $mode;
}

/**
 * One field of stat($path) at a byte offset, or false when it cannot be
 * stat'ed. $width is the member's size: off_t/time_t/ino_t are 8 on every
 * supported target, but uid_t/gid_t are 4 — reading those as i64 would swallow
 * the next member.
 */
function __mc_stat_at(string $path, int $off, int $width = 8): int|false
{
    $buf = \Runtime\Libc\calloc(\__mc_stat_off(11) + 64, 1);
    if ($buf === null) {
        return false;
    }
    if (!\__mc_stat_into($path, $buf, false)) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $v = $width === 4 ? \peek_u32($buf, $off) : \peek_i64($buf, $off);
    \Runtime\Libc\free($buf);
    return $v;
}

/** Size of a file in bytes, or false on failure. */
function filesize(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(10));
}

/** Last-modification time as a Unix timestamp, or false on failure. */
function filemtime(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(8));
}

/** Last-access time as a Unix timestamp, or false on failure. */
function fileatime(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(7));
}

/** Inode-change time as a Unix timestamp, or false on failure. */
function filectime(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(9));
}

/** Owner uid, or false on failure. */
function fileowner(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(5), 4);
}

/** Group gid, or false on failure. */
function filegroup(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(6), 4);
}

/** Inode number, or false on failure. */
function fileinode(string $filename): int|false
{
    return \__mc_stat_at($filename, \__mc_stat_off(4));
}

/** Full st_mode (type bits included, as php.net returns), or false on failure. */
function fileperms(string $filename): int|false
{
    $m = \__mc_stat_mode($filename);
    if ($m < 0) {
        return false;
    }
    return $m;
}

/** Whether $filename is a directory. */
function is_dir(string $filename): bool
{
    $m = \__mc_stat_mode($filename);
    return $m >= 0 && ($m & 0170000) === 0040000;
}

/** Whether $filename is a regular file. */
function is_file(string $filename): bool
{
    $m = \__mc_stat_mode($filename);
    return $m >= 0 && ($m & 0170000) === 0100000;
}

/** Whether $filename is a symbolic link (lstat: the link itself, not its target). */
function is_link(string $filename): bool
{
    $m = \__mc_stat_mode($filename, true);
    return $m >= 0 && ($m & 0170000) === 0120000;
}

/** php.net filetype(): fifo/char/dir/block/file/link/socket/unknown, or false. */
function filetype(string $filename): string|false
{
    $m = \__mc_stat_mode($filename, true);
    if ($m < 0) {
        return false;
    }
    $t = $m & 0170000;
    if ($t === 0040000) {
        return 'dir';
    }
    if ($t === 0100000) {
        return 'file';
    }
    if ($t === 0120000) {
        return 'link';
    }
    if ($t === 0010000) {
        return 'fifo';
    }
    if ($t === 0020000) {
        return 'char';
    }
    if ($t === 0060000) {
        return 'block';
    }
    if ($t === 0140000) {
        return 'socket';
    }
    return 'unknown';
}

// ── directory iteration ───────────────────────────────────────────────────
//
// scandir() is deliberately absent: it needs sort(), and sort() lives in the
// prelude, which is emitted linkonce_odr into both stdlib.o and the calling
// program. Those two copies are NOT interchangeable — each is specialised for
// its own module's memory mode — so the linker keeping one arbitrarily gave
// scandir the wrong body. Lands once the specialisation is encoded in the
// mangled name.
// struct dirent also moves: d_name sits at 21 on Darwin (after a u64 d_ino, a
// u64 d_seekoff, u16 d_reclen, u16 d_namlen, u8 d_type) and at 19 on Linux
// (u64 d_ino, i64 d_off, u16 d_reclen, u8 d_type).

/** Byte offset of dirent.d_name for the running host. */
function __mc_dirent_name_off(): int
{
    static $off = 0;
    if ($off !== 0) {
        return $off;
    }
    // Reuse the stat probe: it already resolved and verified the host.
    $buf = \Runtime\Libc\calloc(2048, 1);
    if ($buf === null) {
        throw new \RuntimeException('readdir: cannot allocate a uname buffer');
    }
    \Runtime\Libc\uname($buf);
    $sys = \cstr_to_str($buf);
    \Runtime\Libc\free($buf);
    $off = \substr($sys, 0, 6) === 'Darwin' ? 21 : 19;
    return $off;
}

/**
 * Open a directory handle, or false on failure.
 * @return resource|false
 */
function opendir(string $directory)
{
    $d = \Runtime\Libc\sys_opendir($directory);
    if ($d === null) {
        return false;
    }
    return $d;
}

/**
 * Next entry name, or false when the directory is exhausted. Note php.net's
 * quirk: an entry legitimately named "0" is falsy, so callers must compare
 * with `!== false`.
 * @param resource $dir_handle
 * @return string|false
 */
function readdir(\Ffi\Ptr $dir_handle)
{
    $e = \Runtime\Libc\sys_readdir($dir_handle);
    if ($e === null) {
        return false;
    }
    return \cstr_to_str(\ptr_offset($e, \__mc_dirent_name_off()));
}

/**
 * Close a directory handle.
 * @param resource $dir_handle
 */
function closedir(\Ffi\Ptr $dir_handle): void
{
    \Runtime\Libc\sys_closedir($dir_handle);
}

/**
 * Rewind a directory handle to the start.
 * @param resource $dir_handle
 */
function rewinddir(\Ffi\Ptr $dir_handle): void
{
    \Runtime\Libc\sys_rewinddir($dir_handle);
}
