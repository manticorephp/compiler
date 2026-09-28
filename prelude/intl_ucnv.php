<?php

// ext/intl UConverter (php-src ext/intl/converter/converter.c) over ICU's ucnv_* C API.
//
// A subclass that overrides toUCallback / fromUCallback gets ICU callbacks, as in Zend:
// fn_to_ptr() needs a literal function name, so ICU always calls one of two fixed
// trampolines, and the context pointer it carries is the object's id, which the trampoline
// resolves through __McUcnv::$live. An object is live only while ICU may call back for it
// (a conversion, a clone, its close) — the registry never keeps one alive. Nothing may throw
// out of a trampoline (a longjmp over ICU's frames): an exception is parked and rethrown once
// ICU has returned.

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_open')]
function __mc_icu_ucnv_open(string $name, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_close')]
function __mc_icu_ucnv_close(\Ffi\Ptr $cnv): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_clone')]
function __mc_icu_ucnv_clone(\Ffi\Ptr $cnv, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_getName')]
function __mc_icu_ucnv_getName(\Ffi\Ptr $cnv, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_getType'), \Ffi\CType('int')]
function __mc_icu_ucnv_getType(\Ffi\Ptr $cnv): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_setSubstChars')]
function __mc_icu_ucnv_setSubstChars(\Ffi\Ptr $cnv, string $chars, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_getSubstChars')]
function __mc_icu_ucnv_getSubstChars(\Ffi\Ptr $cnv, \Ffi\Ptr $buf, \Ffi\Ptr $len, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_toUChars'), \Ffi\CType('int')]
function __mc_icu_ucnv_toUChars(\Ffi\Ptr $cnv, \Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, string $src,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_fromUChars'), \Ffi\CType('int')]
function __mc_icu_ucnv_fromUChars(\Ffi\Ptr $cnv, \Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $src,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_countAvailable'), \Ffi\CType('int')]
function __mc_icu_ucnv_countAvailable(): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_getAvailableName')]
function __mc_icu_ucnv_getAvailableName(#[\Ffi\CType('int')] int $i): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_countAliases'), \Ffi\CType('int')]
function __mc_icu_ucnv_countAliases(string $name, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_getAlias')]
function __mc_icu_ucnv_getAlias(string $name, #[\Ffi\CType('int')] int $n, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_countStandards'), \Ffi\CType('int')]
function __mc_icu_ucnv_countStandards(): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_getStandard')]
function __mc_icu_ucnv_getStandard(#[\Ffi\CType('int')] int $n, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_setToUCallBack')]
function __mc_icu_ucnv_setToUCallBack(\Ffi\Ptr $cnv, \Ffi\Ptr $fn, \Ffi\Ptr $ctx, \Ffi\Ptr $oldFn,
    \Ffi\Ptr $oldCtx, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ucnv_setFromUCallBack')]
function __mc_icu_ucnv_setFromUCallBack(\Ffi\Ptr $cnv, \Ffi\Ptr $fn, \Ffi\Ptr $ctx, \Ffi\Ptr $oldFn,
    \Ffi\Ptr $oldCtx, \Ffi\Ptr $err): void {}

/** The objects ICU may call back for right now, by id, and a parked callback exception. */
final class __McUcnv
{
    /** @var array<int, UConverter> */
    public static array $live = [];
    public static int $next = 0;
    public static ?\Throwable $cbErr = null;
}

function __mc_ucnv_i32(int $v): int
{
    return (($v & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
}

/**
 * UConverterToUCallback. args: {u16 size; UBool flush; UConverter*; const char* source@16;
 * sourceLimit@24; UChar* target@32; targetLimit@40; int32* offsets@48}.
 */
function __mc_ucnv_to_u_tramp(\Ffi\Ptr $ctx, \Ffi\Ptr $args, \Ffi\Ptr $units, int $length, int $reason, \Ffi\Ptr $err): int
{
    $o = __McUcnv::$live[\ptr_to_int($ctx)] ?? null;
    if ($o === null || __McUcnv::$cbErr !== null) { return 0; }
    $length = \__mc_ucnv_i32($length);
    $src = \peek_i64($args, 16);
    $lim = \peek_i64($args, 24);
    $source = $src !== 0 ? \str_from_buffer(\int_to_ptr($src), $lim - $src) : "";
    $cu = \ptr_to_int($units) !== 0 && $length > 0 ? \str_from_buffer($units, $length) : "";
    $error = \peek_i32($err, 0);
    try {
        $r = $o->toUCallback(\__mc_ucnv_i32($reason), $source, $cu, $error);
        $o->__mcAppendToU($r, $args);
    } catch (\Throwable $e) {
        __McUcnv::$cbErr = $e;
        return 0;
    }
    if (\is_int($error)) { \poke_i32($err, 0, $error); }
    return 0;
}

/**
 * UConverterFromUCallback. args: {u16 size; UBool flush; UConverter*; const UChar* source@16;
 * sourceLimit@24; char* target@32; targetLimit@40; int32* offsets@48}.
 */
function __mc_ucnv_from_u_tramp(\Ffi\Ptr $ctx, \Ffi\Ptr $args, \Ffi\Ptr $units, int $length, int $codePoint,
    int $reason, \Ffi\Ptr $err): int
{
    $o = __McUcnv::$live[\ptr_to_int($ctx)] ?? null;
    if ($o === null || __McUcnv::$cbErr !== null) { return 0; }
    $length = \__mc_ucnv_i32($length);
    $cps = [];
    $i = 0;
    while ($i < $length) {
        $c = \peek_u16($units, 2 * $i);
        $i = $i + 1;
        if ($c >= 0xD800 && $c <= 0xDBFF && $i < $length) {
            $d = \peek_u16($units, 2 * $i);
            if ($d >= 0xDC00 && $d <= 0xDFFF) {
                $c = 0x10000 + (($c - 0xD800) << 10) + ($d - 0xDC00);
                $i = $i + 1;
            }
        }
        $cps[] = $c;
    }
    $error = \peek_i32($err, 0);
    try {
        $r = $o->fromUCallback(\__mc_ucnv_i32($reason), $cps, \__mc_ucnv_i32($codePoint), $error);
        $o->__mcAppendFromU($r, $args);
    } catch (\Throwable $e) {
        __McUcnv::$cbErr = $e;
        return 0;
    }
    if (\is_int($error)) { \poke_i32($err, 0, $error); }
    return 0;
}

class UConverter
{
    public const REASON_UNASSIGNED = 0;
    public const REASON_ILLEGAL = 1;
    public const REASON_IRREGULAR = 2;
    public const REASON_RESET = 3;
    public const REASON_CLOSE = 4;
    public const REASON_CLONE = 5;
    public const UNSUPPORTED_CONVERTER = -1;
    public const SBCS = 0;
    public const DBCS = 1;
    public const MBCS = 2;
    public const LATIN_1 = 3;
    public const UTF8 = 4;
    public const UTF16_BigEndian = 5;
    public const UTF16_LittleEndian = 6;
    public const UTF32_BigEndian = 7;
    public const UTF32_LittleEndian = 8;
    public const EBCDIC_STATEFUL = 9;
    public const ISO_2022 = 10;
    public const LMBCS_1 = 11;
    public const LMBCS_2 = 12;
    public const LMBCS_3 = 13;
    public const LMBCS_4 = 14;
    public const LMBCS_5 = 15;
    public const LMBCS_6 = 16;
    public const LMBCS_8 = 17;
    public const LMBCS_11 = 18;
    public const LMBCS_16 = 19;
    public const LMBCS_17 = 20;
    public const LMBCS_18 = 21;
    public const LMBCS_19 = 22;
    public const LMBCS_LAST = 22;
    public const HZ = 23;
    public const SCSU = 24;
    public const ISCII = 25;
    public const US_ASCII = 26;
    public const UTF7 = 27;
    public const BOCU1 = 28;
    public const UTF16 = 29;
    public const UTF32 = 30;
    public const CESU8 = 31;
    public const IMAP_MAILBOX = 32;

    private int $__mcSrc = 0;
    private int $__mcDest = 0;
    private int $__mcId = 0;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    public function __construct(?string $destination_encoding = null, ?string $source_encoding = null)
    {
        \__mc_intl_reset();
        __McUcnv::$next = __McUcnv::$next + 1;
        $this->__mcId = __McUcnv::$next;
        $fn = "UConverter::__construct";
        if (!$this->__mcSetEncoding($fn, true, $source_encoding ?? "utf-8")) {
            throw new IntlException($this->__mcWhat($fn));
        }
        if (!$this->__mcSetEncoding($fn, false, $destination_encoding ?? "utf-8")) {
            throw new IntlException($this->__mcWhat($fn));
        }
    }

    /** The constructor's exception text: the error as set, less its trailing code name. */
    private function __mcWhat(string $fn): string
    {
        return $this->__mcErrMessage === "" ? $fn . "()"
            : \substr($this->__mcErrMessage, 0, \strlen($this->__mcErrMessage) - \strlen(\intl_error_name($this->__mcErrCode)) - 2);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    private function __mcHooked(): bool
    {
        return \get_class($this) !== "UConverter";
    }

    public function __clone()
    {
        __McUcnv::$next = __McUcnv::$next + 1;
        $old = $this->__mcId;
        $this->__mcId = __McUcnv::$next;
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
        $e = \__mc_icu_err();
        __McUcnv::$live[$old] = $this;
        if ($this->__mcSrc !== 0) { $this->__mcSrc = \ptr_to_int(\__mc_icu_ucnv_clone(\int_to_ptr($this->__mcSrc), $e)); }
        if ($this->__mcDest !== 0) { $this->__mcDest = \ptr_to_int(\__mc_icu_ucnv_clone(\int_to_ptr($this->__mcDest), $e)); }
        unset(__McUcnv::$live[$old]);
        \__mc_icu_free($e);
        if ($this->__mcHooked()) {
            if ($this->__mcSrc !== 0) { $this->__mcSetCallbacks(\int_to_ptr($this->__mcSrc)); }
            if ($this->__mcDest !== 0) { $this->__mcSetCallbacks(\int_to_ptr($this->__mcDest)); }
        }
    }

    public function __destruct()
    {
        __McUcnv::$live[$this->__mcId] = $this;
        if ($this->__mcSrc !== 0) { \__mc_icu_ucnv_close(\int_to_ptr($this->__mcSrc)); $this->__mcSrc = 0; }
        if ($this->__mcDest !== 0) { \__mc_icu_ucnv_close(\int_to_ptr($this->__mcDest)); $this->__mcDest = 0; }
        unset(__McUcnv::$live[$this->__mcId]);
        __McUcnv::$cbErr = null;
    }

    public function __mcFail(string $fn, int $code, string $what): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    private function __mcResetErr(): void
    {
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    private function __mcSetCallbacks(\Ffi\Ptr $cnv): bool
    {
        $e = \__mc_icu_err();
        $ctx = \int_to_ptr($this->__mcId);
        $z = \int_to_ptr(0);
        \__mc_icu_ucnv_setToUCallBack($cnv, \fn_to_ptr('__mc_ucnv_to_u_tramp'), $ctx, $z, $z, $e);
        $c = \peek_i32($e, 0);
        \poke_i32($e, 0, 0);
        \__mc_icu_ucnv_setFromUCallBack($cnv, \fn_to_ptr('__mc_ucnv_from_u_tramp'), $ctx, $z, $z, $e);
        $c2 = \peek_i32($e, 0);
        \__mc_icu_free($e);
        return $c <= 0 && $c2 <= 0;
    }

    /** php_converter_set_encoding for this object's source (`$src`) or destination converter. */
    public function __mcSetEncoding(string $fn, bool $src, string $enc): bool
    {
        $e = \__mc_icu_err();
        $cnv = \__mc_icu_ucnv_open($enc, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c === -122) {
            $n = \cstr_to_str(\__mc_icu_ucnv_getName($cnv, \__mc_icu_err()));
            \__mc_intl_fail($fn, "Ambiguous encoding specified, using " . $n, $c);
        } elseif ($c > 0) {
            $this->__mcFail($fn, $c, "returned error " . (string)$c . ": " . \intl_error_name($c));
            return false;
        }
        if ($this->__mcHooked() && !$this->__mcSetCallbacks($cnv)) {
            \__mc_icu_ucnv_close($cnv);
            return false;
        }
        $old = $src ? $this->__mcSrc : $this->__mcDest;
        if ($old !== 0) {
            __McUcnv::$live[$this->__mcId] = $this;
            \__mc_icu_ucnv_close(\int_to_ptr($old));
            unset(__McUcnv::$live[$this->__mcId]);
        }
        if ($src) { $this->__mcSrc = \ptr_to_int($cnv); } else { $this->__mcDest = \ptr_to_int($cnv); }
        return true;
    }

    public function convert(string $str, bool $reverse = false): string|false
    {
        $this->__mcResetErr();
        $d = $reverse ? $this->__mcSrc : $this->__mcDest;
        $s = $reverse ? $this->__mcDest : $this->__mcSrc;
        __McUcnv::$live[$this->__mcId] = $this;
        __McUcnv::$cbErr = null;
        $out = \__mc_ucnv_do_convert("UConverter::convert", $d, $s, $str, $this);
        unset(__McUcnv::$live[$this->__mcId]);
        $err = __McUcnv::$cbErr;
        __McUcnv::$cbErr = null;
        if ($err !== null) { throw $err; }
        return $out ?? false;
    }

    /** php_converter_append_toUnicode_target: a callback's answer into args->target (UChars). */
    public function __mcAppendToU(mixed $v, \Ffi\Ptr $args): void
    {
        if ($v === null) { return; }
        if (\is_array($v)) {
            foreach ($v as $x) { $this->__mcAppendToU($x, $args); }
            return;
        }
        if (\is_int($v)) {
            if ($v < 0 || $v > 0x10FFFF) {
                $this->__mcFail("UConverter::toUCallback", 1, \sprintf("Invalid codepoint U+%04x", $v));
                return;
            }
            if ($v > 0xFFFF) {
                if ($this->__mcTargetFits($args, 2, 2)) {
                    $this->__mcPushU16($args, (($v - 0x10000) >> 10) | 0xD800);
                    $this->__mcPushU16($args, (($v - 0x10000) & 0x3FF) | 0xDC00);
                }
                return;
            }
            if ($this->__mcTargetFits($args, 1, 2)) { $this->__mcPushU16($args, $v); }
            return;
        }
        if (\is_string($v)) {
            $n = \strlen($v);
            $i = 0;
            while ($i !== $n && $this->__mcTargetFits($args, 1, 2)) {
                $r = \__mc_ucnv_u8_next($v, $i, $n);
                $i = $i + ($r & 7);
                $this->__mcPushU16($args, ($r >> 3) & 0xFFFF);
            }
            return;
        }
        $this->__mcFail("UConverter::toUCallback", 1, "toUCallback() specified illegal type for substitution character");
    }

    /** php_converter_append_fromUnicode_target: a callback's answer into args->target (bytes). */
    public function __mcAppendFromU(mixed $v, \Ffi\Ptr $args): void
    {
        if ($v === null) { return; }
        if (\is_array($v)) {
            foreach ($v as $x) { $this->__mcAppendFromU($x, $args); }
            return;
        }
        if (\is_int($v)) {
            if ($this->__mcTargetFits($args, 1, 1)) {
                $t = \peek_i64($args, 32);
                \poke_i8(\int_to_ptr($t), 0, $v & 0xFF);
                \poke_i64($args, 32, $t + 1);
            }
            return;
        }
        if (\is_string($v)) {
            $n = \strlen($v);
            if ($this->__mcTargetFits($args, $n, 1)) {
                $t = \peek_i64($args, 32);
                if ($n > 0) { \__mc_c_memcpy(\int_to_ptr($t), $v, $n); }
                \poke_i64($args, 32, $t + $n);
            }
            return;
        }
        $this->__mcFail("UConverter::fromUCallback", 1, "fromUCallback() specified illegal type for substitution character");
    }

    private function __mcTargetFits(\Ffi\Ptr $args, int $needed, int $unit): bool
    {
        $avail = \intdiv(\peek_i64($args, 40) - \peek_i64($args, 32), $unit);
        if ($avail < $needed) {
            $this->__mcFail("UConverter::convert", 15, "Buffer overrun " . (string)$needed . " bytes needed, " . (string)$avail . " available");
            return false;
        }
        return true;
    }

    private function __mcPushU16(\Ffi\Ptr $args, int $u): void
    {
        $t = \peek_i64($args, 32);
        \poke_i16(\int_to_ptr($t), 0, $u);
        \poke_i64($args, 32, $t + 2);
    }

    public function toUCallback(int $reason, string $source, string $codeUnits, &$error): string|int|array|null
    {
        return $this->__mcDefaultCallback($reason, $error);
    }

    public function fromUCallback(int $reason, array $source, int $codePoint, &$error): string|int|array|null
    {
        return $this->__mcDefaultCallback($reason, $error);
    }

    /** php_converter_default_callback: the source converter's substitution chars. */
    private function __mcDefaultCallback(int $reason, mixed &$error): ?string
    {
        if ($reason !== 0 && $reason !== 1 && $reason !== 2) { return null; }
        if ($this->__mcSrc === 0) {
            $this->__mcFail("UConverter::toUCallback", 27, "Source Converter has not been initialized yet");
            $error = 27;
            return "\x1A";
        }
        $r = \__mc_ucnv_subst(\int_to_ptr($this->__mcSrc));
        $error = __McIcuStatus::$code;
        return $r ?? "\x1A";
    }

    public static function getAliases(string $name): array|false|null
    {
        \__mc_intl_reset();
        $fn = "UConverter::getAliases";
        $e = \__mc_icu_err();
        $n = \__mc_icu_ucnv_countAliases($name, $e) & 0xFFFF;
        $c = \peek_i32($e, 0);
        if ($c > 0) {
            \__mc_icu_free($e);
            \__mc_intl_fail($fn, "returned error " . (string)$c . ": " . \intl_error_name($c), $c);
            return false;
        }
        $out = [];
        $i = 0;
        while ($i < $n) {
            \poke_i32($e, 0, 0);
            $p = \__mc_icu_ucnv_getAlias($name, $i, $e);
            $c = \peek_i32($e, 0);
            if ($c > 0) {
                \__mc_icu_free($e);
                \__mc_intl_fail($fn, "returned error " . (string)$c . ": " . \intl_error_name($c), $c);
                return null;
            }
            $out[] = \cstr_to_str($p);
            $i = $i + 1;
        }
        \__mc_icu_free($e);
        return $out;
    }

    /** @return string[] */
    public static function getAvailable(): array
    {
        \__mc_intl_reset();
        $out = [];
        $n = \__mc_icu_ucnv_countAvailable();
        $i = 0;
        while ($i < $n) {
            $out[] = \cstr_to_str(\__mc_icu_ucnv_getAvailableName($i));
            $i = $i + 1;
        }
        return $out;
    }

    private function __mcName(int $cnv): string|false|null
    {
        $this->__mcResetErr();
        if ($cnv === 0) { return null; }
        $e = \__mc_icu_err();
        $p = \__mc_icu_ucnv_getName(\int_to_ptr($cnv), $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            $this->__mcFail("UConverter::getSourceEncoding", $c, "returned error " . (string)$c . ": " . \intl_error_name($c));
            return false;
        }
        return \cstr_to_str($p);
    }

    public function getDestinationEncoding(): string|false|null
    {
        return $this->__mcName($this->__mcDest);
    }

    public function getSourceEncoding(): string|false|null
    {
        return $this->__mcName($this->__mcSrc);
    }

    public function getDestinationType(): int|false|null
    {
        $this->__mcResetErr();
        return $this->__mcDest === 0 ? null : \__mc_icu_ucnv_getType(\int_to_ptr($this->__mcDest));
    }

    public function getSourceType(): int|false|null
    {
        $this->__mcResetErr();
        return $this->__mcSrc === 0 ? null : \__mc_icu_ucnv_getType(\int_to_ptr($this->__mcSrc));
    }

    public function getErrorCode(): int
    {
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }

    /** @return string[]|null */
    public static function getStandards(): ?array
    {
        \__mc_intl_reset();
        $out = [];
        $n = \__mc_icu_ucnv_countStandards() & 0xFFFF;
        $e = \__mc_icu_err();
        $i = 0;
        while ($i < $n) {
            \poke_i32($e, 0, 0);
            $p = \__mc_icu_ucnv_getStandard($i, $e);
            $c = \peek_i32($e, 0);
            if ($c > 0) {
                \__mc_icu_free($e);
                \__mc_intl_fail("UConverter::getStandards", "returned error " . (string)$c . ": " . \intl_error_name($c), $c);
                return null;
            }
            $out[] = \cstr_to_str($p);
            $i = $i + 1;
        }
        \__mc_icu_free($e);
        return $out;
    }

    public function getSubstChars(): string|false|null
    {
        $this->__mcResetErr();
        if ($this->__mcSrc === 0) { return null; }
        $r = \__mc_ucnv_subst(\int_to_ptr($this->__mcSrc));
        if ($r === null) {
            $c = __McIcuStatus::$code;
            $this->__mcFail("UConverter::getSubstChars", $c, "returned error " . (string)$c . ": " . \intl_error_name($c));
            return false;
        }
        return $r;
    }

    public static function reasonText(int $reason): string
    {
        \__mc_intl_reset();
        $names = ["REASON_UNASSIGNED", "REASON_ILLEGAL", "REASON_IRREGULAR", "REASON_RESET", "REASON_CLOSE", "REASON_CLONE"];
        if ($reason < 0 || $reason > 5) {
            throw new \ValueError("UConverter::reasonText(): Argument #1 (\$reason) must be a UConverter::REASON_* constant");
        }
        return $names[$reason];
    }

    public function setDestinationEncoding(string $encoding): bool
    {
        $this->__mcResetErr();
        return $this->__mcSetEncoding("UConverter::setDestinationEncoding", false, $encoding);
    }

    public function setSourceEncoding(string $encoding): bool
    {
        $this->__mcResetErr();
        return $this->__mcSetEncoding("UConverter::setSourceEncoding", true, $encoding);
    }

    public function setSubstChars(string $chars): bool
    {
        $fn = "UConverter::setSubstChars";
        $this->__mcResetErr();
        $ok = true;
        foreach ([[$this->__mcSrc, "Source"], [$this->__mcDest, "Destination"]] as [$cnv, $label]) {
            if ($cnv === 0) {
                $this->__mcFail($fn, 27, $label . " Converter has not been initialized yet");
                $ok = false;
                continue;
            }
            $e = \__mc_icu_err();
            \__mc_icu_ucnv_setSubstChars(\int_to_ptr($cnv), $chars, (\strlen($chars) & 0xFF) - ((\strlen($chars) & 0x80) << 1), $e);
            $c = \peek_i32($e, 0);
            \__mc_icu_free($e);
            if ($c > 0) {
                $this->__mcFail($fn, $c, "returned error " . (string)$c . ": " . \intl_error_name($c));
                $ok = false;
            }
        }
        return $ok;
    }

    /** @param array<string, mixed>|null $options */
    public static function transcode(string $str, string $toEncoding, string $fromEncoding, ?array $options = null): string|false
    {
        $fn = "UConverter::transcode";
        \__mc_intl_reset();
        $e = \__mc_icu_err();
        $src = \__mc_icu_ucnv_open($fromEncoding, $e);
        $c = \peek_i32($e, 0);
        if ($c > 0) {
            \__mc_icu_free($e);
            \__mc_intl_fail($fn, "Error setting encoding: " . (string)$c . " - " . \intl_error_name($c), $c);
            return false;
        }
        if ($c === -122) {
            \__mc_intl_fail($fn, "Ambiguous encoding specified, using " . \cstr_to_str(\__mc_icu_ucnv_getName($src, \__mc_icu_err())), $c);
        }
        \poke_i32($e, 0, 0);
        $dst = \__mc_icu_ucnv_open($toEncoding, $e);
        $c = \peek_i32($e, 0);
        if ($c > 0) {
            \__mc_icu_free($e);
            \__mc_icu_ucnv_close($src);
            \__mc_intl_fail($fn, "Error setting encoding: " . (string)$c . " - " . \intl_error_name($c), $c);
            return false;
        }
        if ($c === -122) {
            \__mc_intl_fail($fn, "Ambiguous encoding specified, using " . \cstr_to_str(\__mc_icu_ucnv_getName($dst, \__mc_icu_err())), $c);
        }
        \poke_i32($e, 0, 0);
        $err = 0;
        if ($options !== null && $options !== []) {
            if (isset($options["from_subst"]) && \is_string($options["from_subst"])) {
                $fs = $options["from_subst"];
                \__mc_icu_ucnv_setSubstChars($src, $fs, \strlen($fs) & 0x7F, $e);
                $err = \peek_i32($e, 0);
            }
            if ($err <= 0 && isset($options["to_subst"]) && \is_string($options["to_subst"])) {
                \poke_i32($e, 0, 0);
                $ts = $options["to_subst"];
                \__mc_icu_ucnv_setSubstChars($dst, $ts, \strlen($ts) & 0x7F, $e);
                $err = \peek_i32($e, 0);
            }
        }
        \__mc_icu_free($e);
        $out = false;
        if ($err <= 0) {
            $out = \__mc_ucnv_do_convert($fn, \ptr_to_int($dst), \ptr_to_int($src), $str, null) ?? false;
        } else {
            \__mc_intl_fail($fn, "returned error " . (string)$err . ": " . \intl_error_name($err), $err);
        }
        \__mc_icu_ucnv_close($src);
        \__mc_icu_ucnv_close($dst);
        return $out;
    }
}

/** ucnv_getSubstChars; null with the status in __McIcuStatus::$code on failure. */
function __mc_ucnv_subst(\Ffi\Ptr $cnv): ?string
{
    $buf = \__mc_icu_malloc(128);
    $len = \__mc_icu_malloc(8);
    \poke_i8($len, 0, 127);
    $e = \__mc_icu_err();
    \__mc_icu_ucnv_getSubstChars($cnv, $buf, $len, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    __McIcuStatus::$code = $c;
    $out = $c > 0 ? null : \str_from_buffer($buf, \peek_u8($len, 0));
    \__mc_icu_free($len);
    \__mc_icu_free($buf);
    return $out;
}

/** U8_NEXT from `$i` — the code point (U_SENTINEL -1 on a malformed sequence) << 3 | bytes taken. */
function __mc_ucnv_u8_next(string $s, int $i, int $n): int
{
    $c = \ord($s[$i]);
    if ($c < 0x80) { return ($c << 3) | 1; }
    if ($c < 0xC2 || $c > 0xF4) { return -7; }
    $need = $c < 0xE0 ? 1 : ($c < 0xF0 ? 2 : 3);
    $lo = 0x80;
    $hi = 0xBF;
    if ($c === 0xE0) { $lo = 0xA0; } elseif ($c === 0xED) { $hi = 0x9F; }
    elseif ($c === 0xF0) { $lo = 0x90; } elseif ($c === 0xF4) { $hi = 0x8F; }
    $v = $c & (0x7F >> ($need + 1));
    $k = 1;
    while ($need > 0) {
        if ($i + $k >= $n) { return -8 + $k; }
        $t = \ord($s[$i + $k]);
        if ($t < $lo || $t > $hi) { return -8 + $k; }
        $v = ($v << 6) | ($t & 0x3F);
        $lo = 0x80;
        $hi = 0xBF;
        $k = $k + 1;
        $need = $need - 1;
    }
    return ($v << 3) | $k;
}

/** php_converter_do_convert: `$src_cnv` bytes → UTF-16 → `$dest_cnv` bytes; null on failure. */
function __mc_ucnv_do_convert(string $fn, int $dest, int $srcCnv, string $str, ?UConverter $obj): ?string
{
    $fail = function (int $c) use ($fn, $obj): void {
        $what = "returned error " . (string)$c . ": " . \intl_error_name($c);
        if ($obj !== null) { $obj->__mcFail($fn, $c, $what); }
        else { \__mc_intl_fail($fn, $what, $c); }
    };
    if ($srcCnv === 0 || $dest === 0) {
        if ($obj !== null) { $obj->__mcFail($fn, 27, "Internal converters not initialized"); }
        else { \__mc_intl_fail($fn, "Internal converters not initialized", 27); }
        return null;
    }
    $s = \int_to_ptr($srcCnv);
    $d = \int_to_ptr($dest);
    $n = \strlen($str);
    $e = \__mc_icu_err();
    $tl = 1 + \__mc_icu_ucnv_toUChars($s, \int_to_ptr(0), 0, $str, $n, $e);
    $c = \peek_i32($e, 0);
    if ($c > 0 && $c !== 15) {
        \__mc_icu_free($e);
        $fail($c);
        return null;
    }
    $tmp = \__mc_icu_malloc(2 * ($tl + 1));
    \poke_i32($e, 0, 0);
    $tl = \__mc_icu_ucnv_toUChars($s, $tmp, $tl, $str, $n, $e);
    $c = \peek_i32($e, 0);
    if ($c > 0) {
        \__mc_icu_free($e);
        \__mc_icu_free($tmp);
        $fail($c);
        return null;
    }
    $rl = \__mc_icu_ucnv_fromUChars($d, \int_to_ptr(0), 0, $tmp, $tl, $e);
    $c = \peek_i32($e, 0);
    if ($c > 0 && $c !== 15) {
        \__mc_icu_free($e);
        \__mc_icu_free($tmp);
        $fail($c);
        return null;
    }
    $buf = \__mc_icu_malloc($rl + 1);
    \poke_i32($e, 0, 0);
    $rl = \__mc_icu_ucnv_fromUChars($d, $buf, $rl + 1, $tmp, $tl, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    \__mc_icu_free($tmp);
    if ($c > 0) {
        \__mc_icu_free($buf);
        $fail($c);
        return null;
    }
    $out = \str_from_buffer($buf, $rl);
    \__mc_icu_free($buf);
    return $out;
}
