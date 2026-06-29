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

// `uname(struct utsname*)` — fills the buffer; `sysname` ("Darwin"/"Linux")
// is the first member at offset 0, NUL-terminated. The caller passes a
// generously-sized zeroed buffer (utsname is ~1.3 KB on macOS).
#[Library('c'), Symbol('uname')]
function uname(Ptr $buf): int {}

#[Library('c'), Symbol('unlink')]
function sys_unlink(string $path): int {}

#[Library('c'), Symbol('getcwd'), Give]
function sys_getcwd(Ptr $buf, #[CType('size_t')] int $size): Ptr {}
