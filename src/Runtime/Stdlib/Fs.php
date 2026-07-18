<?php

/**
 * php.net filesystem functions that need only scalar libc calls — no struct
 * marshalling (the stat/dirent family lives elsewhere and needs peek_*).
 *
 * Global namespace so user code calling `mkdir($p)` resolves here (the
 * compiler's inline-builtin table does not handle these, so they fall through
 * to user-function resolution).
 *
 * A libc buffer from calloc has no rc header: cut it to a real string with
 * str_from_buffer (known length) or cstr_to_str (NUL-terminated), then free.
 */

/**
 * Create a directory. With $recursive, missing parents are created too and an
 * already-existing *parent* is fine — but an existing leaf still fails, as
 * php.net specifies.
 */
function mkdir(string $directory, int $permissions = 0777, bool $recursive = false): bool
{
    if (!$recursive) {
        return \Runtime\Libc\sys_mkdir($directory, $permissions) === 0;
    }
    if ($directory === '') {
        return false;
    }
    $cur = $directory[0] === '/' ? '' : '.';
    $parts = \explode('/', $directory);
    $n = \count($parts);
    $made = false;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $seg = $parts[$i];
        if ($seg === '') {
            continue;
        }
        $cur = $cur . '/' . $seg;
        if (\file_exists($cur)) {
            $made = false;
            continue;
        }
        if (\Runtime\Libc\sys_mkdir($cur, $permissions) !== 0) {
            return false;
        }
        $made = true;
    }
    // php.net: true only when the leaf itself was created.
    return $made;
}

/** Remove an empty directory. */
function rmdir(string $directory): bool
{
    return \Runtime\Libc\sys_rmdir($directory) === 0;
}

/** Rename (move) a file or directory. */
function rename(string $from, string $to): bool
{
    return \Runtime\Libc\sys_rename($from, $to) === 0;
}

/** Copy a file, streaming in 64 KiB chunks rather than loading it whole. */
function copy(string $from, string $to): bool
{
    $in = \Runtime\Libc\fopen($from, 'rb');
    if ($in === null) {
        return false;
    }
    $out = \Runtime\Libc\fopen($to, 'wb');
    if ($out === null) {
        \Runtime\Libc\fclose($in);
        return false;
    }
    $cap = 65536;
    $buf = \Runtime\Libc\calloc($cap, 1);
    if ($buf === null) {
        \Runtime\Libc\fclose($in);
        \Runtime\Libc\fclose($out);
        return false;
    }
    $ok = true;
    while (true) {
        $n = \Runtime\Libc\fread($buf, 1, $cap, $in);
        if ($n <= 0) {
            break;
        }
        if (\Runtime\Libc\fwrite_buf($buf, 1, $n, $out) !== $n) {
            $ok = false;
            break;
        }
    }
    \Runtime\Libc\free($buf);
    \Runtime\Libc\fclose($in);
    \Runtime\Libc\fclose($out);
    return $ok;
}

/**
 * Create the file if missing, and set its timestamps. An omitted $mtime falls
 * back to $atime and then to time(); an omitted $atime mirrors $mtime.
 */
function touch(string $filename, ?int $mtime = null, ?int $atime = null): bool
{
    if (!\file_exists($filename)) {
        $fp = \Runtime\Libc\fopen($filename, 'wb');
        if ($fp === null) {
            return false;
        }
        \Runtime\Libc\fclose($fp);
    }
    $mt = $mtime;
    if ($mt === null) {
        $mt = $atime;
    }
    if ($mt === null) {
        $mt = \time();
    }
    $at = $atime;
    if ($at === null) {
        $at = $mt;
    }
    // struct timeval[2] = { {atime, 0}, {mtime, 0} }. tv_sec is 8 bytes on both
    // hosts; tv_usec is 4 (Darwin) or 8 (Linux), but it is zeroed either way, so
    // an i64 zero at +8 / +24 covers the member and any tail padding.
    $buf = \Runtime\Libc\calloc(32, 1);
    if ($buf === null) {
        return false;
    }
    \poke_i64($buf, 0, $at);
    \poke_i64($buf, 8, 0);
    \poke_i64($buf, 16, $mt);
    \poke_i64($buf, 24, 0);
    $r = \Runtime\Libc\sys_utimes($filename, $buf);
    \Runtime\Libc\free($buf);
    return $r === 0;
}

/** Change file permissions. */
function chmod(string $filename, int $permissions): bool
{
    return \Runtime\Libc\sys_chmod($filename, $permissions) === 0;
}

/** Change file owner/group by numeric id (php.net also accepts a name). */
function chown(string $filename, int $user): bool
{
    return \Runtime\Libc\sys_chown($filename, $user, -1) === 0;
}

/** Change file group by numeric id. */
function chgrp(string $filename, int $group): bool
{
    return \Runtime\Libc\sys_chown($filename, -1, $group) === 0;
}

/** Create a symbolic link at $link pointing to $target. */
function symlink(string $target, string $link): bool
{
    return \Runtime\Libc\sys_symlink($target, $link) === 0;
}

/** Create a hard link. */
function link(string $target, string $link): bool
{
    return \Runtime\Libc\sys_link($target, $link) === 0;
}

/** Target of a symbolic link, or false on failure. */
function readlink(string $path): string|false
{
    $cap = 4096;
    $buf = \Runtime\Libc\calloc($cap + 1, 1);
    if ($buf === null) {
        return false;
    }
    $n = \Runtime\Libc\sys_readlink($path, $buf, $cap);
    if ($n < 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    // readlink(2) does NOT NUL-terminate — cut to the returned length, so
    // str_from_buffer, never cstr_to_str.
    $s = \str_from_buffer($buf, $n);
    \Runtime\Libc\free($buf);
    return $s;
}

/** Canonical absolute path with symlinks resolved, or false when it does not exist. */
function realpath(string $path): string|false
{
    // POSIX requires the buffer to hold PATH_MAX bytes when it is not NULL.
    $buf = \Runtime\Libc\calloc(4097, 1);
    if ($buf === null) {
        return false;
    }
    $p = \Runtime\Libc\sys_realpath($path, $buf);
    if ($p === null) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $s = \cstr_to_str($buf);
    \Runtime\Libc\free($buf);
    return $s;
}

/** Whether a path is writable. access(path, W_OK=2). */
function is_writable(string $filename): bool
{
    return \Runtime\Libc\access($filename, 2) === 0;
}

/** Alias of is_writable(). */
function is_writeable(string $filename): bool
{
    return \Runtime\Libc\access($filename, 2) === 0;
}

/** Whether a path is executable. access(path, X_OK=1). */
function is_executable(string $filename): bool
{
    return \Runtime\Libc\access($filename, 1) === 0;
}

/**
 * Underlying file descriptor of a stream. Internal: php.net has no fileno(),
 * so claiming the global name would collide with a user function rather than
 * add parity.
 * @param \Resource $stream
 */
function __mc_fileno(\Resource $stream): int
{
    if (\__mc_stream_is_net($stream)) {
        // Already an fd (a TLS stream's addr is the fd too). Routing it through
        // fileno() would int_to_ptr a small integer and hand libc a bogus FILE*.
        return $stream->addr;
    }
    return \Runtime\Libc\sys_fileno(\int_to_ptr($stream->addr));
}

/**
 * Truncate an open stream to $size bytes.
 * @param \Resource $stream
 */
function ftruncate(\Resource $stream, int $size): bool
{
    if (\__mc_stream_is_net($stream)) {
        return false;   // php: cannot truncate a socket/TLS stream
    }
    \Runtime\Libc\fflush(\int_to_ptr($stream->addr));
    return \Runtime\Libc\sys_ftruncate(\__mc_fileno($stream), $size) === 0;
}

/**
 * Advisory lock. $operation is LOCK_SH (1) / LOCK_EX (2) / LOCK_UN (3),
 * optionally | LOCK_NB (4).
 *
 * PHP's LOCK_* constants are PHP's own values and do NOT all match flock(2):
 * LOCK_UN is 3 in PHP but 8 to the OS, where 3 means LOCK_SH|LOCK_EX and is
 * rejected with EINVAL. LOCK_SH/LOCK_EX/LOCK_NB coincide numerically on both
 * Darwin and Linux. Zend performs the same translation.
 * @param \Resource $stream
 */
function flock(\Resource $stream, int $operation): bool
{
    $op = $operation & 3;
    if ($op === 3) { $op = 8; }
    if (($operation & 4) !== 0) { $op = $op | 4; }
    return \Runtime\Libc\sys_flock(\__mc_fileno($stream), $op) === 0;
}

/**
 * Flush the OS buffers for a stream to disk.
 * @param \Resource $stream
 */
function fsync(\Resource $stream): bool
{
    if (\__mc_stream_is_net($stream)) {
        return false;   // nothing buffered, nothing to sync
    }
    \Runtime\Libc\fflush(\int_to_ptr($stream->addr));
    return \Runtime\Libc\sys_fsync(\__mc_fileno($stream)) === 0;
}

/**
 * fdatasync(2) is not portable to Darwin; fsync is a valid (stronger)
 * substitute, so php.net's contract still holds.
 * @param \Resource $stream
 */
function fdatasync(\Resource $stream): bool
{
    return \fsync($stream);
}

/** Current umask, or set it and return the previous one. */
function umask(?int $mask = null): int
{
    if ($mask === null) {
        // No read-only umask(2): set-then-restore. Single-threaded, so the
        // window is not observable.
        $old = \Runtime\Libc\sys_umask(0);
        \Runtime\Libc\sys_umask($old);
        return $old;
    }
    return \Runtime\Libc\sys_umask($mask);
}

/**
 * Read a file into an array of lines. Lines keep their trailing newline unless
 * FILE_IGNORE_NEW_LINES (2). FILE_SKIP_EMPTY_LINES (4) drops lines that are
 * empty *after* that stripping — on its own it therefore changes nothing,
 * which matches php.net.
 * @return string[]|false
 */
function file(string $filename, int $flags = 0): array|false
{
    $raw = \file_get_contents($filename);
    if ($raw === false) {
        return false;
    }
    // file_get_contents returns string|false, i.e. a cell. Unbox once here:
    // indexing a cell is correct but costs a NaN-tag branch per access, and
    // this loop touches every byte of the file.
    $data = (string)$raw;
    $strip = ($flags & 2) !== 0;
    $skip = ($flags & 4) !== 0;
    $out = [];
    $len = \strlen($data);
    $start = 0;
    for ($i = 0; $i < $len; $i = $i + 1) {
        if ($data[$i] !== "\n") {
            continue;
        }
        $line = \substr($data, $start, $i - $start + 1);
        if ($strip) {
            $line = \rtrim($line, "\r\n");
        }
        if (!($skip && $line === '')) {
            $out[] = $line;
        }
        $start = $i + 1;
    }
    if ($start < $len) {
        $line = \substr($data, $start);
        if ($strip) {
            $line = \rtrim($line, "\r\n");
        }
        if (!($skip && $line === '')) {
            $out[] = $line;
        }
    }
    return $out;
}

/** Write a file straight to stdout; returns the byte count or false. */
function readfile(string $filename): int|false
{
    $data = \file_get_contents($filename);
    if ($data === false) {
        return false;
    }
    echo $data;
    return \strlen($data);
}

// fprintf/vfprintf/vsprintf/vprintf are NOT implemented, and cannot be until a
// RUNTIME format engine exists. sprintf is a codegen builtin that requires a
// LITERAL format (EmitLlvmBuiltins::biSprintf bails unless arg 0 is a
// STRING_CONST) and translates it to a C format at COMPILE time, then calls
// snprintf. An fprintf() written here would have a runtime $format and a
// runtime $values array — neither survives that design. Writing a PHP-side
// formatter instead would fork sprintf's semantics into a second
// implementation, so this waits for a shared runtime formatter.

/**
 * Copy everything left on $stream to stdout and return the byte count.
 * Chunked rather than slurped: a passthru of a large file should not need the
 * whole file resident.
 * @param \Resource $stream
 */
function fpassthru(\Resource $stream): int
{
    $total = 0;
    while (true) {
        $chunk = \fread($stream, 8192);
        if ($chunk === '') {
            break;
        }
        echo $chunk;
        $total = $total + \strlen($chunk);
    }
    return $total;
}

/**
 * One byte from a stream, or false at EOF.
 * @param \Resource $stream
 * @return string|false
 */
function fgetc(\Resource $stream)
{
    $s = \fread($stream, 1);
    if ($s === '') {
        return false;
    }
    return $s;
}

/** Directory for temporary files, without a trailing slash. */
function sys_get_temp_dir(): string
{
    $t = \getenv('TMPDIR');
    if ($t === false || $t === '') {
        return '/tmp';
    }
    // getenv returns string|false, i.e. a cell — unbox once, then let rtrim do
    // the scan rather than looping over bytes here.
    $s = \rtrim((string)$t, '/');
    if ($s === '') {
        return '/';
    }
    return $s;
}

/** No-op: nothing here caches stat results, so there is nothing to clear. */
function clearstatcache(bool $clear_realpath_cache = false, string $filename = ''): void
{
}

/**
 * Match $filename against a shell wildcard $pattern.
 *
 * $flags go straight to fnmatch(3). php's FNM_* constants ARE the host's header
 * values — unlike LOCK_*, which php numbers itself — so passing them through
 * unchanged is the correct behaviour, not an oversight. See the FNM_ block in
 * LowerPrelude for why the values are resolved at compile time.
 */
function fnmatch(string $pattern, string $filename, int $flags = 0): bool
{
    return \Runtime\Libc\sys_fnmatch($pattern, $filename, $flags) === 0;
}

/** Change the current working directory. Returns true on success. */
function chdir(string $directory): bool
{
    return \Runtime\Libc\sys_chdir($directory) === 0;
}

// glob() is implemented HERE rather than over libc glob(3), for the same reason
// php stopped using the system one in 8.3: it is not one function, it is a
// different function per libc. glob_t moves (gl_pathv at 32 on Darwin, 8 on
// glibc), the flags are renumbered wholesale, the return codes flip sign
// (Darwin -1/-2/-3, glibc 1/2/3), GLOB_ONLYDIR does not exist on Darwin and is
// only a hint on glibc — and, decisively, **musl has no GLOB_BRACE at all**, so
// on Alpine (i.e. most containers) brace expansion would silently do nothing.
// Four workarounds and still wrong on a mainstream target: the layer is not
// worth having. fnmatch(3) IS used — it is uniform across all three libcs.

/** Whether a path component contains a glob metacharacter. */
function __mc_glob_has_magic(string $s): bool
{
    $n = \strlen($s);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $s[$i];
        if ($c === '*' || $c === '?' || $c === '[') {
            return true;
        }
    }
    return false;
}

/**
 * Expand `{a,b}` alternations, outermost group first, recursively.
 *
 * Brace expansion is NOT a match operation — it is textual, happens before any
 * path is touched, and nests. Commas inside a NESTED brace belong to that inner
 * group, so the scan tracks depth rather than splitting on every comma.
 * @return string[]
 */
function __mc_glob_expand_braces(string $p): array
{
    $n = \strlen($p);
    $open = -1;
    for ($i = 0; $i < $n; $i = $i + 1) {
        if ($p[$i] === '{') { $open = $i; break; }
    }
    if ($open < 0) {
        return [$p];
    }
    $depth = 0;
    $close = -1;
    $parts = [];
    $cur = '';
    for ($i = $open; $i < $n; $i = $i + 1) {
        $c = $p[$i];
        if ($c === '{') {
            $depth = $depth + 1;
            if ($depth === 1) { continue; }
        } elseif ($c === '}') {
            $depth = $depth - 1;
            if ($depth === 0) { $close = $i; break; }
        } elseif ($c === ',' && $depth === 1) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur = $cur . $c;
    }
    if ($close < 0) {
        // Unbalanced '{' is a literal, as in the shell.
        return [$p];
    }
    $parts[] = $cur;
    $pre = \substr($p, 0, $open);
    $post = \substr($p, $close + 1);
    $out = [];
    foreach ($parts as $alt) {
        foreach (\__mc_glob_expand_braces($pre . $alt . $post) as $e) {
            $out[] = $e;
        }
    }
    return $out;
}

/**
 * fnmatch flags for one glob path component.
 *
 * FNM_PERIOD (4) makes a leading dot un-matchable by a wildcard, which is what
 * makes `glob("*")` skip dotfiles. FNM_NOESCAPE differs by host — 1 on Darwin,
 * 2 on glibc — and cannot be named here (the stdlib may not reference an FNM_*
 * constant, or the Zend-seed bootstrap dies), so it is resolved at runtime.
 */
function __mc_glob_fnm_flags(int $globFlags): int
{
    $f = 4;
    if (($globFlags & 0x1000) !== 0) {
        $f = $f | (\__mc_host_is_darwin() ? 1 : 2);
    }
    return $f;
}

/**
 * Match one component against the entries of $dir, sorted byte-wise.
 * @return string[]
 */
function __mc_glob_children(string $dir, string $pat, int $fnmFlags): array
{
    $entries = \scandir($dir === '' ? '.' : $dir, 2);
    if ($entries === false) {
        return [];
    }
    $out = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        // (string) is load-bearing, not decoration: scandir returns
        // `string[]|false`, so $entries is a CELL and $e comes out NaN-boxed.
        // Appending it raw would fill an array this function declares `string[]`
        // with boxed cells — the caller then releases them as string pointers
        // and faults on the tag. The cast makes the element a real string.
        $name = (string)$e;
        if (\fnmatch($pat, $name, $fnmFlags)) {
            $out[] = $name;
        }
    }
    \usort($out, function (string $a, string $b): int {
        return \__mc_strcmp_bytes($a, $b);
    });
    return $out;
}

/**
 * Paths matching a shell pattern.
 *
 * Walks the pattern one '/'-separated component at a time: a component with no
 * metacharacter is joined literally (no directory read), one with a
 * metacharacter fans out over scandir + fnmatch. Every component but the last
 * must be a directory to be descended.
 * @return string[]
 */
function glob(string $pattern, int $flags = 0)
{
    $onlyDir = ($flags & 0x40000000) !== 0;
    $mark = ($flags & 0x0008) !== 0;
    $fnm = \__mc_glob_fnm_flags($flags);

    $pats = ($flags & 0x0080) !== 0
        ? \__mc_glob_expand_braces($pattern)
        : [$pattern];

    $out = [];
    foreach ($pats as $pat) {
        if ($pat === '') {
            continue;
        }
        $abs = $pat[0] === '/';
        $segs = \explode('/', $abs ? \substr($pat, 1) : $pat);
        // Seed: the root for an absolute pattern, cwd-relative otherwise.
        $cur = [$abs ? '' : ''];
        $first = true;
        foreach ($segs as $seg) {
            if ($seg === '') {
                continue;
            }
            $next = [];
            foreach ($cur as $base) {
                $prefix = $first
                    ? ($abs ? '/' : '')
                    : $base . '/';
                if (!\__mc_glob_has_magic($seg)) {
                    $p = $prefix . $seg;
                    if (\file_exists($p)) {
                        $next[] = $p;
                    }
                    continue;
                }
                $dir = $first ? ($abs ? '/' : '.') : $base;
                foreach (\__mc_glob_children($dir, $seg, $fnm) as $name) {
                    $next[] = $prefix . $name;
                }
            }
            $cur = $next;
            $first = false;
            if (\count($cur) === 0) {
                break;
            }
        }
        foreach ($cur as $p) {
            if ($onlyDir && !\is_dir($p)) {
                continue;
            }
            $out[] = ($mark && \is_dir($p)) ? $p . '/' : $p;
        }
    }

    if (\count($out) === 0 && ($flags & 0x0010) !== 0) {
        // GLOB_NOCHECK: the pattern itself stands in for an empty result.
        return [$pattern];
    }
    if (($flags & 0x0020) === 0) {
        \usort($out, function (string $a, string $b): int {
            return \__mc_strcmp_bytes($a, $b);
        });
    }
    return $out;
}

/**
 * Open a unique temporary file, removed when closed. Returns a file resource,
 * or false on failure.
 * @return \Resource|false
 */
function tmpfile()
{
    $f = \Runtime\Libc\sys_tmpfile();
    if ($f === null) {
        return false;
    }
    // Wrap like fopen()/opendir() do: the raw FILE* must not escape this file,
    // or it reaches the \Resource-typed f* family as a bare address.
    return new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($f));
}

/**
 * Create a unique file in $directory and return its path, or false on failure.
 *
 * php.net's contract is that the file is CREATED (mode 0600), not merely named,
 * so this goes through mkstemp(3): composing a name and opening it afterwards
 * would be a TOCTOU race. mkstemp writes the chosen suffix back into its
 * template, hence the raw buffer.
 * @return string|false
 */
function tempnam(string $directory, string $prefix)
{
    $dir = \rtrim($directory, '/');
    if ($dir === '') {
        $dir = '/tmp';
    }
    $tmpl = $dir . '/' . $prefix . 'XXXXXX';
    $buf = \Runtime\Libc\calloc(\strlen($tmpl) + 1, 1);
    if ($buf === null) {
        return false;
    }
    \Runtime\Libc\strcpy($buf, $tmpl);
    $fd = \Runtime\Libc\sys_mkstemp($buf);
    if ($fd < 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    \Runtime\Libc\sys_close($fd);
    $path = \cstr_to_str($buf);
    \Runtime\Libc\free($buf);
    return $path;
}
