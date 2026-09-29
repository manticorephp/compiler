<?php

// ext/intl idn_to_ascii / idn_to_utf8 (php-src ext/intl/idn/idn.cpp) over ICU's UTS #46
// C API (uidna_openUTS46 + the UTF-8 name conversions).

const IDNA_DEFAULT = 48;
const IDNA_ALLOW_UNASSIGNED = 1;
const IDNA_USE_STD3_RULES = 2;
const IDNA_CHECK_BIDI = 4;
const IDNA_CHECK_CONTEXTJ = 8;
const IDNA_NONTRANSITIONAL_TO_ASCII = 16;
const IDNA_NONTRANSITIONAL_TO_UNICODE = 32;
const INTL_IDNA_VARIANT_UTS46 = 1;
const IDNA_ERROR_EMPTY_LABEL = 1;
const IDNA_ERROR_LABEL_TOO_LONG = 2;
const IDNA_ERROR_DOMAIN_NAME_TOO_LONG = 4;
const IDNA_ERROR_LEADING_HYPHEN = 8;
const IDNA_ERROR_TRAILING_HYPHEN = 16;
const IDNA_ERROR_HYPHEN_3_4 = 32;
const IDNA_ERROR_LEADING_COMBINING_MARK = 64;
const IDNA_ERROR_DISALLOWED = 128;
const IDNA_ERROR_PUNYCODE = 256;
const IDNA_ERROR_LABEL_HAS_DOT = 512;
const IDNA_ERROR_INVALID_ACE_LABEL = 1024;
const IDNA_ERROR_BIDI = 2048;
const IDNA_ERROR_CONTEXTJ = 4096;

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uidna_openUTS46')]
function __mc_icu_uidna_openUTS46(#[\Ffi\CType('uint')] int $options, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uidna_close')]
function __mc_icu_uidna_close(\Ffi\Ptr $idna): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uidna_nameToASCII_UTF8'), \Ffi\CType('int')]
function __mc_icu_uidna_nameToASCII_UTF8(\Ffi\Ptr $idna, string $name, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $info, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uidna_nameToUnicodeUTF8'), \Ffi\CType('int')]
function __mc_icu_uidna_nameToUnicodeUTF8(\Ffi\Ptr $idna, string $name, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $info, \Ffi\Ptr $err): int { return 0; }

/** php_intl_idn_handoff + php_intl_idn_to_46. `$info` is written only when `$wantInfo`. */
function __mc_idn(string $fn, bool $toAscii, string $domain, int $flags, int $variant, bool $wantInfo, mixed &$info): string|false
{
    \__mc_intl_reset();
    if ($domain === "") {
        throw new \ValueError($fn . "(): Argument #1 (\$domain) must not be empty");
    }
    if ($variant !== 1) {
        throw new \ValueError($fn . "(): Argument #2 (\$flags) must be INTL_IDNA_VARIANT_UTS46");
    }
    if ($wantInfo) { $info = []; }
    $e = \__mc_icu_err();
    $idna = \__mc_icu_uidna_openUTS46($flags & 0xFFFFFFFF, $e);
    $c = \peek_i32($e, 0);
    if ($c > 0) {
        \__mc_icu_free($e);
        \__mc_intl_fail($fn, "failed to open UIDNA instance", $c);
        return false;
    }
    // UIDNAInfo {int16 size; UBool isTransitionalDifferent, reservedB3; uint32 errors; int32 ×2}.
    $ui = \__mc_icu_malloc(16);
    \poke_i64($ui, 0, 0);
    \poke_i64($ui, 8, 0);
    \poke_i16($ui, 0, 16);
    $cap = $toAscii ? 255 : 252 * 4;
    $buf = \__mc_icu_malloc($cap + 1);
    $len = $toAscii
        ? \__mc_icu_uidna_nameToASCII_UTF8($idna, $domain, \strlen($domain), $buf, $cap, $ui, $e)
        : \__mc_icu_uidna_nameToUnicodeUTF8($idna, $domain, \strlen($domain), $buf, $cap, $ui, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    \__mc_icu_uidna_close($idna);
    __McIntlError::$code = $c > 0 ? $c : 0;
    if ($len >= $cap || $c > 0) {
        \__mc_icu_free($buf);
        \__mc_icu_free($ui);
        if ($c > 0) { \__mc_intl_fail($fn, "failed to convert name", $c); }
        return false;
    }
    $out = \str_from_buffer($buf, $len);
    \__mc_icu_free($buf);
    $errors = \peek_u32($ui, 4);
    $transitional = \peek_u8($ui, 2) !== 0;
    \__mc_icu_free($ui);
    if ($wantInfo) {
        $info = ["result" => $out, "isTransitionalDifferent" => $transitional, "errors" => $errors];
    }
    return $errors === 0 ? $out : false;
}

function idn_to_ascii(string $domain, int $flags = IDNA_DEFAULT, int $variant = INTL_IDNA_VARIANT_UTS46, &$idna_info = null): string|false
{
    return \__mc_idn("idn_to_ascii", true, $domain, $flags, $variant, \func_num_args() >= 4, $idna_info);
}

function idn_to_utf8(string $domain, int $flags = IDNA_DEFAULT, int $variant = INTL_IDNA_VARIANT_UTS46, &$idna_info = null): string|false
{
    return \__mc_idn("idn_to_utf8", false, $domain, $flags, $variant, \func_num_args() >= 4, $idna_info);
}
