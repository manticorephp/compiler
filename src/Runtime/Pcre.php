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
