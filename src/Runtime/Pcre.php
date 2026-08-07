<?php

namespace Runtime\Pcre;

use Ffi\Library;
use Ffi\Ptr;
use Ffi\Symbol;

// Thin FFI binding to the host PCRE2 (8-bit code unit width). Symbols carry
// the `_8` suffix that PCRE2 stamps on when PCRE2_CODE_UNIT_WIDTH == 8.
//
// Opaque handles (pcre2_code*, pcre2_match_data*) are carried as raw i64
// addresses (`int`) so a NULL is a plain `=== 0` check. PCRE2_SIZE == size_t
// == i64. A C `int` return (pcre2_match) has undefined upper 32 bits when
// declared i64 — the caller masks the low 32 bits and sign-extends.

#[Library('pcre2-8'), Symbol('pcre2_compile_8')]
function compile(string $pattern, int $length, int $options, Ptr $errorcode, Ptr $erroroffset, int $ccontext): int {}

#[Library('pcre2-8'), Symbol('pcre2_match_data_create_from_pattern_8')]
function matchDataCreate(int $code, int $gcontext): int {}

#[Library('pcre2-8'), Symbol('pcre2_match_8')]
function exec(int $code, string $subject, int $length, int $startoffset, int $options, int $matchData, int $mcontext): int {}

#[Library('pcre2-8'), Symbol('pcre2_get_ovector_pointer_8')]
function ovectorPtr(int $matchData): Ptr {}

#[Library('pcre2-8'), Symbol('pcre2_get_ovector_count_8')]
function ovectorCount(int $matchData): int {}

#[Library('pcre2-8'), Symbol('pcre2_match_data_free_8')]
function matchDataFree(int $matchData): void {}

#[Library('pcre2-8'), Symbol('pcre2_code_free_8')]
function codeFree(int $code): void {}

/**
 * `int pcre2_pattern_info(const pcre2_code *, uint32_t what, void *where)`.
 *
 * The only route to a pattern's NAME TABLE, which is what `(?P<name>…)` groups
 * need: PCRE2 reports them nowhere else, so without this every named group was
 * simply absent from `$matches` — and symfony/routing is named groups all the
 * way down.
 *
 * `$where` is an OUT slot whose width depends on `$what`: a uint32 for
 * NAMECOUNT / NAMEENTRYSIZE, a pointer for NAMETABLE. One 8-byte calloc covers
 * both, read back with peek_u32 / peek_i64 respectively.
 *
 * The `int` return needs the function-level CType so it sign-extends: an error
 * comes back negative, and without the sext it reads as ~4 billion.
 */
#[Library('pcre2-8'), Symbol('pcre2_pattern_info_8'), \Ffi\CType('int')]
function patternInfo(int $code, int $what, Ptr $where): int {}
