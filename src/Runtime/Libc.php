<?php

namespace Runtime\Libc;

use Ffi\CType;
use Ffi\Give;
use Ffi\Library;
use Ffi\Ptr;
use Ffi\Symbol;
use Ffi\Take;

// ── Memory ─────────────────────────────────────────────────────────────

#[Library('c'), Symbol('malloc'), Give]
function malloc(#[CType('size_t')] int $size): Ptr {}

#[Library('c'), Symbol('calloc'), Give]
function calloc(#[CType('size_t')] int $n, #[CType('size_t')] int $size): Ptr {}

#[Library('c'), Symbol('realloc'), Give]
function realloc(#[Take] Ptr $p, #[CType('size_t')] int $size): Ptr {}

#[Library('c'), Symbol('free')]
function free(#[Take] Ptr $p): void {}

#[Library('c'), Symbol('memcpy')]
function memcpy(Ptr $dst, Ptr $src, #[CType('size_t')] int $n): Ptr {}

#[Library('c'), Symbol('memset')]
function memset(Ptr $dst, #[CType('int')] int $byte, #[CType('size_t')] int $n): Ptr {}

#[Library('c'), Symbol('memcmp')]
function memcmp(string $a, string $b, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('memchr')]
function memchr(Ptr $hay, #[CType('int')] int $byte, #[CType('size_t')] int $n): Ptr {}

// ── Strings (NUL-terminated) ───────────────────────────────────────────

#[Library('c'), Symbol('strlen')]
function strlen(string $s): int {}

#[Library('c'), Symbol('strcmp')]
function strcmp(string $a, string $b): int {}

#[Library('c'), Symbol('strncmp')]
function strncmp(string $a, string $b, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('strcasecmp')]
function strcasecmp(string $a, string $b): int {}

#[Library('c'), Symbol('strncasecmp')]
function strncasecmp(string $a, string $b, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('strchr')]
function strchr(string $s, #[CType('int')] int $byte): Ptr {}

#[Library('c'), Symbol('strrchr')]
function strrchr(string $s, #[CType('int')] int $byte): Ptr {}

// `strstr` returns NULL when not found. We type the return as `int`
// (raw address) rather than Ptr so the PHP-side null check is a
// plain `=== 0` instead of needing a Ptr-object wrap that doesn't
// exist when libc hands us a null pointer.
#[Library('c'), Symbol('strstr')]
function strstr(string $hay, string $needle): int {}

#[Library('c'), Symbol('strdup'), Give]
function strdup(string $s): Ptr {}

#[Library('c'), Symbol('strncpy')]
function strncpy(Ptr $dst, string $src, #[CType('size_t')] int $n): Ptr {}

#[Library('c'), Symbol('strcpy')]
function strcpy(Ptr $dst, string $src): Ptr {}

#[Library('c'), Symbol('strcat')]
function strcat(Ptr $dst, string $src): Ptr {}

// ── stdio ──────────────────────────────────────────────────────────────

#[Library('c'), Symbol('puts')]
function puts(string $s): int {}

#[Library('c'), Symbol('write')]
function write(#[CType('int')] int $fd, string $buf, #[CType('size_t')] int $n): int {}

#[Library('c'), Symbol('read')]
function read(#[CType('int')] int $fd, Ptr $buf, #[CType('size_t')] int $n): int {}

// ── files / filesystem ─────────────────────────────────────────────────

#[Library('c'), Symbol('fopen'), Give]
function fopen(string $path, string $mode): Ptr {}

#[Library('c'), Symbol('fclose')]
function fclose(#[Take] Ptr $stream): int {}

#[Library('c'), Symbol('fread')]
function fread(Ptr $buf, #[CType('size_t')] int $size, #[CType('size_t')] int $count, Ptr $stream): int {}

#[Library('c'), Symbol('fwrite')]
function fwrite(string $buf, #[CType('size_t')] int $size, #[CType('size_t')] int $count, Ptr $stream): int {}

// Same `fwrite` symbol as above, but taking a raw buffer instead of a headered
// string — for copying bytes straight from an fread buffer without a
// str_from_buffer round-trip through the heap.
#[Library('c'), Symbol('fwrite')]
function fwrite_buf(Ptr $buf, #[CType('size_t')] int $size, #[CType('size_t')] int $count, Ptr $stream): int {}

#[Library('c'), Symbol('fseek')]
function fseek(Ptr $stream, #[CType('long')] int $offset, #[CType('int')] int $whence): int {}

#[Library('c'), Symbol('ftell')]
function ftell(Ptr $stream): int {}

#[Library('c'), Symbol('feof')]
function feof(Ptr $stream): int {}

#[Library('c'), Symbol('fflush')]
function fflush(Ptr $stream): int {}

// `char *fgets(char *str, int n, FILE *stream)` — fills str, returns str (or
// NULL at EOF / on error). The result aliases the caller's $buf (NOT a fresh
// allocation, so no #[Give]); typed Ptr for the PHP-side EOF null-check.
#[Library('c'), Symbol('fgets')]
function fgets(Ptr $buf, #[CType('int')] int $n, Ptr $stream): Ptr {}

#[Library('c'), Symbol('access')]
function access(string $path, #[CType('int')] int $mode): int {}

// Directory / metadata syscalls. `sys_`-prefixed like sys_unlink/sys_getcwd —
// the plain name is taken by the php.net-facing wrapper in Stdlib/Io.php.
// mode_t/uid_t/gid_t are narrower than int on some hosts; the C ABI promotes
// them, so passing `int` is correct on both Darwin and Linux.

#[Library('c'), Symbol('mkdir')]
function sys_mkdir(string $path, #[CType('int')] int $mode): int {}

#[Library('c'), Symbol('rmdir')]
function sys_rmdir(string $path): int {}

#[Library('c'), Symbol('rename')]
function sys_rename(string $from, string $to): int {}

#[Library('c'), Symbol('chmod')]
function sys_chmod(string $path, #[CType('int')] int $mode): int {}

#[Library('c'), Symbol('chown')]
function sys_chown(string $path, #[CType('int')] int $owner, #[CType('int')] int $group): int {}

#[Library('c'), Symbol('symlink')]
function sys_symlink(string $target, string $link): int {}

#[Library('c'), Symbol('link')]
function sys_link(string $target, string $link): int {}

// `ssize_t readlink(const char *, char *, size_t)` — does NOT NUL-terminate;
// the caller must cut the buffer to the returned length (str_from_buffer),
// never cstr_to_str.
#[Library('c'), Symbol('readlink')]
function sys_readlink(string $path, Ptr $buf, #[CType('size_t')] int $size): int {}

#[Library('c'), Symbol('truncate')]
function sys_truncate(string $path, #[CType('long')] int $length): int {}

#[Library('c'), Symbol('ftruncate')]
function sys_ftruncate(#[CType('int')] int $fd, #[CType('long')] int $length): int {}

#[Library('c'), Symbol('fileno')]
function sys_fileno(Ptr $stream): int {}

#[Library('c'), Symbol('flock')]
function sys_flock(#[CType('int')] int $fd, #[CType('int')] int $op): int {}

#[Library('c'), Symbol('fsync')]
function sys_fsync(#[CType('int')] int $fd): int {}

#[Library('c'), Symbol('umask')]
function sys_umask(#[CType('int')] int $mask): int {}

// `char *realpath(const char *, char *)` — called with a caller-owned
// PATH_MAX buffer (never NULL), so the result aliases $resolved rather than
// being a fresh allocation: no #[Give], and the caller frees the buffer.
// A NULL second arg would malloc instead, but `\Ffi\Ptr::null()` is not
// callable from here — Ptr's methods live in src/Ffi, which is outside the
// src/Runtime tree the stdlib library is built from.
#[Library('c'), Symbol('realpath')]
function sys_realpath(string $path, Ptr $resolved): Ptr {}

// `int utimes(const char *, const struct timeval[2])` — always passed a real
// timeval pair (see the NULL note on sys_realpath).
#[Library('c'), Symbol('utimes')]
function sys_utimes(string $path, Ptr $times): int {}

// ── stat / dirent ──────────────────────────────────────────────────────
// The caller supplies an over-allocated buffer and reads members by offset
// ({@see Stdlib/Stat.php}) — `struct stat` and `struct dirent` have no stable
// layout across targets, so nothing here can name a field.
//
// Symbol caveat: on macOS/x86_64 the modern SDK exports these as
// `stat$INODE64` / `readdir$INODE64`. #[Symbol] is a compile-time constant, so
// that target would need its own binding; arm64 macOS and Linux use the plain
// names below.

#[Library('c'), Symbol('stat')]
function sys_stat(string $path, Ptr $buf): int {}

#[Library('c'), Symbol('lstat')]
function sys_lstat(string $path, Ptr $buf): int {}

#[Library('c'), Symbol('fstat')]
function sys_fstat(#[CType('int')] int $fd, Ptr $buf): int {}

#[Library('c'), Symbol('opendir'), Give]
function sys_opendir(string $path): Ptr {}

// Returns a pointer into the DIR's own storage (NOT a fresh allocation, so no
// #[Give]); NULL at end-of-directory.
#[Library('c'), Symbol('readdir')]
function sys_readdir(Ptr $dir): Ptr {}

#[Library('c'), Symbol('closedir')]
function sys_closedir(#[Take] Ptr $dir): int {}

#[Library('c'), Symbol('rewinddir')]
function sys_rewinddir(Ptr $dir): void {}

// `uname(struct utsname*)` — fills the buffer; `sysname` ("Darwin"/"Linux")
// is the first member at offset 0, NUL-terminated. The caller passes a
// generously-sized zeroed buffer (utsname is ~1.3 KB on macOS).
#[Library('c'), Symbol('uname')]
function uname(Ptr $buf): int {}

#[Library('c'), Symbol('unlink')]
function sys_unlink(string $path): int {}

#[Library('c'), Symbol('getcwd'), Give]
function sys_getcwd(Ptr $buf, #[CType('size_t')] int $size): Ptr {}
