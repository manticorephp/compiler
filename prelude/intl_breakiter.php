<?php

// ext/intl IntlBreakIterator family (php-src ext/intl/breakiterator/*) over ICU's C API:
// ubrk_* for the rule-based iterators, utext_* for php's own code-point iterator. Offsets are
// UTF-8 byte offsets, as Zend's (the text is a UTF-8 UText).

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_open')]
function __mc_icu_ubrk_open_loc(#[\Ffi\CType('int')] int $type, string $locale, \Ffi\Ptr $text,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_openRules')]
function __mc_icu_ubrk_openRules(\Ffi\Ptr $rules, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $text,
    #[\Ffi\CType('int')] int $textLen, \Ffi\Ptr $parseErr, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_openBinaryRules')]
function __mc_icu_ubrk_openBinaryRules(\Ffi\Ptr $rules, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $text,
    #[\Ffi\CType('int')] int $textLen, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_clone')]
function __mc_icu_ubrk_clone(\Ffi\Ptr $bi, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_following'), \Ffi\CType('int')]
function __mc_icu_ubrk_following(\Ffi\Ptr $bi, #[\Ffi\CType('int')] int $off): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_preceding'), \Ffi\CType('int')]
function __mc_icu_ubrk_preceding(\Ffi\Ptr $bi, #[\Ffi\CType('int')] int $off): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_getRuleStatus'), \Ffi\CType('int')]
function __mc_icu_ubrk_getRuleStatus(\Ffi\Ptr $bi): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_getRuleStatusVec'), \Ffi\CType('int')]
function __mc_icu_ubrk_getRuleStatusVec(\Ffi\Ptr $bi, \Ffi\Ptr $fill, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_getBinaryRules'), \Ffi\CType('int')]
function __mc_icu_ubrk_getBinaryRules(\Ffi\Ptr $bi, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_getLocaleByType')]
function __mc_icu_ubrk_getLocaleByType(\Ffi\Ptr $bi, #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_next32'), \Ffi\CType('int')]
function __mc_icu_utext_next32(\Ffi\Ptr $ut): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_previous32'), \Ffi\CType('int')]
function __mc_icu_utext_previous32(\Ffi\Ptr $ut): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_current32'), \Ffi\CType('int')]
function __mc_icu_utext_current32(\Ffi\Ptr $ut): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_next32From'), \Ffi\CType('int')]
function __mc_icu_utext_next32From(\Ffi\Ptr $ut, #[\Ffi\CType('longlong')] int $i): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_previous32From'), \Ffi\CType('int')]
function __mc_icu_utext_previous32From(\Ffi\Ptr $ut, #[\Ffi\CType('longlong')] int $i): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_getNativeIndex'), \Ffi\CType('longlong')]
function __mc_icu_utext_getNativeIndex(\Ffi\Ptr $ut): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_setNativeIndex')]
function __mc_icu_utext_setNativeIndex(\Ffi\Ptr $ut, #[\Ffi\CType('longlong')] int $i): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_nativeLength'), \Ffi\CType('longlong')]
function __mc_icu_utext_nativeLength(\Ffi\Ptr $ut): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_moveIndex32'), \Ffi\CType('char')]
function __mc_icu_utext_moveIndex32(\Ffi\Ptr $ut, #[\Ffi\CType('int')] int $delta): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_clone')]
function __mc_icu_utext_clone(\Ffi\Ptr $dest, \Ffi\Ptr $src, #[\Ffi\CType('int')] int $deep,
    #[\Ffi\CType('int')] int $readOnly, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('u_getVersion')]
function __mc_icu_u_getVersion(\Ffi\Ptr $v): void {}

#[\Ffi\Library('c'), \Ffi\Symbol('memcpy')]
function __mc_c_memcpy(\Ffi\Ptr $dst, string $src, #[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

/**
 * A malloc'd copy of a string ICU keeps pointing into (a break iterator's text, compiled
 * rules), freed with the last object holding it — a clone shares it, as Zend's zval does.
 */
final class __McIcuBytes
{
    public int $ptr = 0;

    public function __construct(public string $bytes)
    {
        $n = \strlen($bytes);
        $p = \__mc_icu_malloc($n + 1);
        if ($n > 0) { \__mc_c_memcpy($p, $bytes, $n); }
        \poke_i8($p, $n, 0);
        $this->ptr = \ptr_to_int($p);
    }

    public function __destruct()
    {
        if ($this->ptr !== 0) {
            \__mc_icu_free(\int_to_ptr($this->ptr));
            $this->ptr = 0;
        }
    }
}

/** The C++ type name Zend's debug info prints (typeid(...).name(), Itanium mangling). */
function __mc_brk_type_name(bool $codePoint): string
{
    if ($codePoint) { return "N3PHP22CodePointBreakIteratorE"; }
    $v = \__mc_icu_malloc(4);
    \__mc_icu_u_getVersion($v);
    $ns = "icu_" . (string)\peek_u8($v, 0);
    \__mc_icu_free($v);
    return "N" . (string)\strlen($ns) . $ns . "22RuleBasedBreakIteratorE";
}

class IntlBreakIterator implements IteratorAggregate
{
    public const DONE = -1;
    public const WORD_NONE = 0;
    public const WORD_NONE_LIMIT = 100;
    public const WORD_NUMBER = 100;
    public const WORD_NUMBER_LIMIT = 200;
    public const WORD_LETTER = 200;
    public const WORD_LETTER_LIMIT = 300;
    public const WORD_KANA = 300;
    public const WORD_KANA_LIMIT = 400;
    public const WORD_IDEO = 400;
    public const WORD_IDEO_LIMIT = 500;
    public const LINE_SOFT = 0;
    public const LINE_SOFT_LIMIT = 100;
    public const LINE_HARD = 100;
    public const LINE_HARD_LIMIT = 200;
    public const SENTENCE_TERM = 0;
    public const SENTENCE_TERM_LIMIT = 100;
    public const SENTENCE_SEP = 100;
    public const SENTENCE_SEP_LIMIT = 200;

    /** A UBreakIterator (rule-based) — or, for a code-point iterator, its UText. */
    protected int $__mcBi = 0;
    protected bool $__mcCodePoint = false;
    protected int $__mcLastCp = -1;
    protected ?__McIcuBytes $__mcText = null;
    protected ?__McIcuBytes $__mcRules = null;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    private function __construct() {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ["valid" => true, "text" => $this->__mcText?->bytes, "type" => \__mc_brk_type_name($this->__mcCodePoint)];
    }

    public function __mcAdopt(int $bi, bool $codePoint): void
    {
        $this->__mcBi = $bi;
        $this->__mcCodePoint = $codePoint;
    }

    public function __clone()
    {
        if ($this->__mcBi === 0) { return; }
        $e = \__mc_icu_err();
        if ($this->__mcCodePoint) {
            $this->__mcBi = \ptr_to_int(\__mc_icu_utext_clone(\int_to_ptr(0), \int_to_ptr($this->__mcBi), 0, 1, $e));
        } else {
            $this->__mcBi = \ptr_to_int(\__mc_icu_ubrk_clone(\int_to_ptr($this->__mcBi), $e));
        }
        \__mc_icu_free($e);
    }

    public function __destruct()
    {
        if ($this->__mcBi === 0) { return; }
        if ($this->__mcCodePoint) { \__mc_icu_utext_close(\int_to_ptr($this->__mcBi)); }
        else { \__mc_icu_ubrk_close(\int_to_ptr($this->__mcBi)); }
        $this->__mcBi = 0;
    }

    protected function __mcReset(): void
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    protected function __mcFail(string $fn, string $what, int $code): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    private static function __mcFactory(string $fn, int $type, ?string $locale): ?IntlBreakIterator
    {
        \__mc_intl_reset();
        $e = \__mc_icu_err();
        $bi = \__mc_icu_ubrk_open_loc($type, $locale ?? \__mc_intl_default_locale(), \int_to_ptr(0), 0, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            \__mc_intl_fail($fn, "error creating BreakIterator", $c);
            return null;
        }
        IntlRuleBasedBreakIterator::$__mcInert = true;
        $o = new IntlRuleBasedBreakIterator("");
        IntlRuleBasedBreakIterator::$__mcInert = false;
        $o->__mcAdopt(\ptr_to_int($bi), false);
        return $o;
    }

    public static function createCharacterInstance(?string $locale = null): ?IntlBreakIterator
    {
        return self::__mcFactory("IntlBreakIterator::createCharacterInstance", 0, $locale);
    }

    public static function createCodePointInstance(): IntlCodePointBreakIterator
    {
        \__mc_intl_reset();
        $e = \__mc_icu_err();
        $ut = \__mc_icu_utext_openUTF8(\int_to_ptr(0), "", 0, $e);
        \__mc_icu_free($e);
        $o = new IntlCodePointBreakIterator();
        $o->__mcAdopt(\ptr_to_int($ut), true);
        return $o;
    }

    public static function createLineInstance(?string $locale = null): ?IntlBreakIterator
    {
        return self::__mcFactory("IntlBreakIterator::createLineInstance", 2, $locale);
    }

    public static function createSentenceInstance(?string $locale = null): ?IntlBreakIterator
    {
        return self::__mcFactory("IntlBreakIterator::createSentenceInstance", 3, $locale);
    }

    public static function createTitleInstance(?string $locale = null): ?IntlBreakIterator
    {
        return self::__mcFactory("IntlBreakIterator::createTitleInstance", 4, $locale);
    }

    public static function createWordInstance(?string $locale = null): ?IntlBreakIterator
    {
        return self::__mcFactory("IntlBreakIterator::createWordInstance", 1, $locale);
    }

    protected function __mcPtr(): \Ffi\Ptr
    {
        return \int_to_ptr($this->__mcBi);
    }

    /** The code-point iterator's answer: DONE on U_SENTINEL, else the native index. */
    private function __mcCpPos(int $cp): int
    {
        $this->__mcLastCp = $cp;
        return $cp === -1 ? -1 : \__mc_icu_utext_getNativeIndex($this->__mcPtr());
    }

    public function current(): int
    {
        $this->__mcReset();
        return $this->__mcCodePoint ? \__mc_icu_utext_getNativeIndex($this->__mcPtr()) : \__mc_icu_ubrk_current($this->__mcPtr());
    }

    public function first(): int
    {
        $this->__mcReset();
        if ($this->__mcCodePoint) {
            \__mc_icu_utext_setNativeIndex($this->__mcPtr(), 0);
            $this->__mcLastCp = -1;
            return 0;
        }
        return \__mc_icu_ubrk_first($this->__mcPtr());
    }

    public function last(): int
    {
        $this->__mcReset();
        if ($this->__mcCodePoint) {
            $pos = \__mc_icu_utext_nativeLength($this->__mcPtr());
            \__mc_icu_utext_setNativeIndex($this->__mcPtr(), $pos);
            $this->__mcLastCp = -1;
            return $pos;
        }
        return \__mc_icu_ubrk_last($this->__mcPtr());
    }

    public function previous(): int
    {
        $this->__mcReset();
        if ($this->__mcCodePoint) { return $this->__mcCpPos(\__mc_icu_utext_previous32($this->__mcPtr())); }
        return \__mc_icu_ubrk_previous($this->__mcPtr());
    }

    private static function __mcInt32(string $fn, int $v, string $name): void
    {
        if ($v < -2147483648 || $v > 2147483647) {
            throw new \ValueError($fn . "(): Argument #1 (\$" . $name . ") must be between -2147483648 and 2147483647");
        }
    }

    public function next(?int $offset = null): int
    {
        if ($offset !== null) { self::__mcInt32("IntlBreakIterator::next", $offset, "offset"); }
        $this->__mcReset();
        if ($this->__mcCodePoint) {
            if ($offset === null) { return $this->__mcCpPos(\__mc_icu_utext_next32($this->__mcPtr())); }
            if (\__mc_icu_utext_moveIndex32($this->__mcPtr(), $offset) !== 0) {
                $this->__mcLastCp = \__mc_icu_utext_current32($this->__mcPtr());
                return \__mc_icu_utext_getNativeIndex($this->__mcPtr());
            }
            $this->__mcLastCp = -1;
            return -1;
        }
        $bi = $this->__mcPtr();
        if ($offset === null) { return \__mc_icu_ubrk_next($bi); }
        // RuleBasedBreakIterator::next(n): n boundaries forward (or back), stopping at DONE.
        $r = 0;
        $n = $offset;
        if ($n > 0) {
            while ($n > 0 && $r !== -1) { $r = \__mc_icu_ubrk_next($bi); $n = $n - 1; }
        } elseif ($n < 0) {
            while ($n < 0 && $r !== -1) { $r = \__mc_icu_ubrk_previous($bi); $n = $n + 1; }
        } else {
            $r = \__mc_icu_ubrk_current($bi);
        }
        return $r;
    }

    public function following(int $offset): int
    {
        self::__mcInt32("IntlBreakIterator::following", $offset, "offset");
        $this->__mcReset();
        if ($this->__mcCodePoint) { return $this->__mcCpPos(\__mc_icu_utext_next32From($this->__mcPtr(), $offset)); }
        return \__mc_icu_ubrk_following($this->__mcPtr(), $offset);
    }

    public function preceding(int $offset): int
    {
        self::__mcInt32("IntlBreakIterator::preceding", $offset, "offset");
        $this->__mcReset();
        if ($this->__mcCodePoint) { return $this->__mcCpPos(\__mc_icu_utext_previous32From($this->__mcPtr(), $offset)); }
        return \__mc_icu_ubrk_preceding($this->__mcPtr(), $offset);
    }

    public function isBoundary(int $offset): bool
    {
        self::__mcInt32("IntlBreakIterator::isBoundary", $offset, "offset");
        $this->__mcReset();
        if ($this->__mcCodePoint) {
            \__mc_icu_utext_setNativeIndex($this->__mcPtr(), $offset);
            return $offset === \__mc_icu_utext_getNativeIndex($this->__mcPtr());
        }
        return \__mc_icu_ubrk_isBoundary($this->__mcPtr(), $offset) !== 0;
    }

    public function getErrorCode(): int
    {
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): string
    {
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }

    public function getLocale(int $type): string|false
    {
        $fn = "IntlBreakIterator::getLocale";
        if ($type !== 0 && $type !== 1) {
            \__mc_intl_fail($fn, "invalid locale type", 1);
            return false;
        }
        $this->__mcReset();
        if ($this->__mcCodePoint) { return ""; }
        $e = \__mc_icu_err();
        $p = \__mc_icu_ubrk_getLocaleByType($this->__mcPtr(), $type, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            $this->__mcFail($fn, "Call to ICU method has failed", $c);
            return false;
        }
        // Zend answers icu::Locale(id).getName(), and ICU names the root locale "".
        $id = \cstr_to_str($p);
        return $id === "root" ? "" : $id;
    }

    public function getPartsIterator(int $type = IntlPartsIterator::KEY_SEQUENTIAL): IntlPartsIterator
    {
        if ($type !== 0 && $type !== 1 && $type !== 2) {
            throw new \ValueError("IntlBreakIterator::getPartsIterator(): Argument #1 (\$type) must be one of IntlPartsIterator::KEY_SEQUENTIAL, "
                . "IntlPartsIterator::KEY_LEFT, or IntlPartsIterator::KEY_RIGHT");
        }
        $this->__mcReset();
        return IntlPartsIterator::__mcOver($this, $type);
    }

    public function getText(): ?string
    {
        $this->__mcReset();
        return $this->__mcText?->bytes;
    }

    public function __mcTextBytes(): string
    {
        return $this->__mcText?->bytes ?? "";
    }

    public function setText(string $text): bool
    {
        $fn = "IntlBreakIterator::setText";
        $this->__mcReset();
        $buf = new __McIcuBytes($text);
        $e = \__mc_icu_err();
        if ($this->__mcCodePoint) {
            $ut = \__mc_icu_utext_openUTF8_ptr($this->__mcPtr(), \int_to_ptr($buf->ptr), \strlen($text), $e);
            $this->__mcBi = \ptr_to_int($ut);
            $this->__mcLastCp = -1;
        } else {
            $ut = \__mc_icu_utext_openUTF8_ptr(\int_to_ptr(0), \int_to_ptr($buf->ptr), \strlen($text), $e);
            $c = \peek_i32($e, 0);
            if ($c > 0) {
                \__mc_icu_free($e);
                $this->__mcFail($fn, "error opening UText", $c);
                return false;
            }
            \__mc_icu_ubrk_setUText($this->__mcPtr(), $ut, $e);
            \__mc_icu_utext_close($ut);
        }
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            $this->__mcFail($fn, "error calling BreakIterator::setText()", $c);
            return false;
        }
        $this->__mcText = $buf;
        return true;
    }

    public function getIterator(): Iterator
    {
        return InternalIterator::__mcBoundaries($this);
    }

    public function __mcLastCodePoint(): int
    {
        return $this->__mcLastCp;
    }

    public function __mcRuleStatus(): int
    {
        return \__mc_icu_ubrk_getRuleStatus($this->__mcPtr());
    }
}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_openUTF8')]
function __mc_icu_utext_openUTF8_ptr(\Ffi\Ptr $ut, \Ffi\Ptr $s, #[\Ffi\CType('longlong')] int $len, \Ffi\Ptr $err): \Ffi\Ptr {}

class IntlRuleBasedBreakIterator extends IntlBreakIterator
{
    public static bool $__mcInert = false;

    public function __construct(string $rules, bool $compiled = false)
    {
        if (self::$__mcInert) { return; }
        if ($this->__mcBi !== 0) {
            throw new \Error("IntlRuleBasedBreakIterator object is already constructed");
        }
        $fn = "IntlRuleBasedBreakIterator::__construct";
        $e = \__mc_icu_err();
        if (!$compiled) {
            $u = \__mc_icu_to16($rules);
            if ($u === null) {
                \__mc_icu_free($e);
                throw new IntlException($fn . "(): rules were not a valid UTF-8 string");
            }
            $pe = \__mc_icu_malloc(72);
            \poke_i32($pe, 0, 0);
            \poke_i32($pe, 4, -1);
            \poke_i16($pe, 8, 0);
            \poke_i16($pe, 40, 0);
            $bi = \__mc_icu_ubrk_openRules($u->buf, $u->len, \int_to_ptr(0), 0, $pe, $e);
            \__mc_icu_free($u->buf);
            $c = \peek_i32($e, 0);
            \__mc_icu_free($e);
            __McIntlError::$code = $c;
            if ($c > 0) {
                $msg = \__mc_brk_parse_error($pe);
                \__mc_icu_free($pe);
                throw new IntlException($fn . "(): unable to create RuleBasedBreakIterator from rules (" . $msg . ")");
            }
            \__mc_icu_free($pe);
            $this->__mcAdopt(\ptr_to_int($bi), false);
            return;
        }
        $buf = new __McIcuBytes($rules);
        $bi = \__mc_icu_ubrk_openBinaryRules(\int_to_ptr($buf->ptr), \strlen($rules), \int_to_ptr(0), 0, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            throw new IntlException($fn . "(): unable to create instance from compiled rules");
        }
        $this->__mcRules = $buf;
        $this->__mcAdopt(\ptr_to_int($bi), false);
    }

    public function getBinaryRules(): string|false
    {
        $this->__mcReset();
        $bi = $this->__mcPtr();
        $e = \__mc_icu_err();
        $n = \__mc_icu_ubrk_getBinaryRules($bi, \int_to_ptr(0), 0, $e);
        \poke_i32($e, 0, 0);
        $buf = \__mc_icu_malloc($n + 1);
        $n = \__mc_icu_ubrk_getBinaryRules($bi, $buf, $n, $e);
        \__mc_icu_free($e);
        $out = \str_from_buffer($buf, $n);
        \__mc_icu_free($buf);
        return $out;
    }

    /** RuleBasedBreakIterator::getRules (C++ only): the UTF-8 rule source in the compiled data. */
    public function getRules(): string|false
    {
        $bin = $this->getBinaryRules();
        if ($bin === false || \strlen($bin) < 48) { return ""; }
        // RBBIDataHeader: fRuleSource / fRuleSourceLen, little-endian u32 at 40 / 44.
        $off = \ord($bin[40]) | (\ord($bin[41]) << 8) | (\ord($bin[42]) << 16) | (\ord($bin[43]) << 24);
        $len = \ord($bin[44]) | (\ord($bin[45]) << 8) | (\ord($bin[46]) << 16) | (\ord($bin[47]) << 24);
        $s = \substr($bin, $off, $len);
        $nul = \strpos($s, "\x00");
        return $nul === false ? $s : \substr($s, 0, $nul);
    }

    public function getRuleStatus(): int
    {
        $this->__mcReset();
        return \__mc_icu_ubrk_getRuleStatus($this->__mcPtr());
    }

    /** @return int[]|false */
    public function getRuleStatusVec(): array|false
    {
        $this->__mcReset();
        $bi = $this->__mcPtr();
        $e = \__mc_icu_err();
        $n = \__mc_icu_ubrk_getRuleStatusVec($bi, \int_to_ptr(0), 0, $e);
        \poke_i32($e, 0, 0);
        $buf = \__mc_icu_malloc(4 * $n + 4);
        $n = \__mc_icu_ubrk_getRuleStatusVec($bi, $buf, $n, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            \__mc_icu_free($buf);
            $this->__mcFail("IntlRuleBasedBreakIterator::getRuleStatusVec", "failed obtaining the status values", $c);
            return false;
        }
        $out = [];
        $i = 0;
        while ($i < $n) { $out[] = \peek_i32($buf, 4 * $i); $i = $i + 1; }
        \__mc_icu_free($buf);
        return $out;
    }
}

/** intl_parse_error_to_string over a UParseError {line, offset, preContext[16], postContext[16]}. */
function __mc_brk_parse_error(\Ffi\Ptr $pe): string
{
    $line = \peek_i32($pe, 0);
    $off = \peek_i32($pe, 4);
    $pre = \__mc_icu_uchars_z(\ptr_offset($pe, 8), 16);
    $post = \__mc_icu_uchars_z(\ptr_offset($pe, 40), 16);
    $s = "parse error ";
    $any = false;
    if ($line > 0) { $s .= "on line " . (string)$line; $any = true; }
    if ($off >= 0) { $s .= ($any ? ", " : "at ") . "offset " . (string)$off; $any = true; }
    if ($pre !== "") { $s .= ($any ? ", " : "") . "after \"" . $pre . "\""; $any = true; }
    if ($post !== "") { $s .= ($any ? ", " : "") . "before or at \"" . $post . "\""; $any = true; }
    return $any ? $s : "no parse error";
}

class IntlCodePointBreakIterator extends IntlBreakIterator
{
    public function getLastCodePoint(): int
    {
        $this->__mcReset();
        return $this->__mcLastCodePoint();
    }
}

/**
 * IntlPartsIterator: the text between consecutive boundaries, keyed by position
 * (KEY_SEQUENTIAL), the left boundary (KEY_LEFT) or the right one (KEY_RIGHT).
 */
class IntlPartsIterator extends IntlIterator
{
    public const KEY_SEQUENTIAL = 0;
    public const KEY_LEFT = 1;
    public const KEY_RIGHT = 2;

    private ?IntlBreakIterator $__mcBrk = null;
    private int $__mcKeyType = 0;
    private ?string $__mcPart = null;
    private int $__mcKey = 0;

    public static function __mcOver(IntlBreakIterator $b, int $type): IntlPartsIterator
    {
        $it = new IntlPartsIterator();
        $it->__mcBrk = $b;
        $it->__mcKeyType = $type;
        return $it;
    }

    private function __mcForward(): void
    {
        $this->__mcPart = null;
        $b = $this->__mcBrk;
        $cur = $b->current();
        if ($cur === -1) { return; }
        $next = $b->next();
        if ($next === -1) { return; }
        if ($this->__mcKeyType === 1) { $this->__mcKey = $cur; }
        elseif ($this->__mcKeyType === 2) { $this->__mcKey = $next; }
        $this->__mcPart = \substr($b->__mcTextBytes(), $cur, $next - $cur);
    }

    public function current(): mixed
    {
        return $this->__mcPart;
    }

    public function key(): mixed
    {
        return $this->__mcKey;
    }

    public function next(): void
    {
        if ($this->__mcKeyType === 0) { $this->__mcKey = $this->__mcKey + 1; }
        $this->__mcForward();
    }

    public function rewind(): void
    {
        $this->__mcKey = 0;
        $this->__mcBrk->first();
        $this->__mcForward();
    }

    public function valid(): bool
    {
        return $this->__mcPart !== null;
    }

    public function getBreakIterator(): IntlBreakIterator
    {
        return $this->__mcBrk;
    }

    public function getRuleStatus(): int
    {
        return $this->__mcBrk->__mcRuleStatus();
    }
}

/** The engine's iterator over an IteratorAggregate's boundaries (IntlBreakIterator::getIterator). */
final class InternalIterator implements Iterator
{
    private ?IntlBreakIterator $__mcBrk = null;
    private int $__mcPos = -1;
    private int $__mcIndex = 0;

    private function __construct() {}

    public static function __mcBoundaries(IntlBreakIterator $b): InternalIterator
    {
        $it = new InternalIterator();
        $it->__mcBrk = $b;
        return $it;
    }

    public function current(): mixed
    {
        return $this->__mcPos === -1 ? null : $this->__mcPos;
    }

    public function key(): mixed
    {
        return $this->__mcIndex;
    }

    public function next(): void
    {
        $this->__mcPos = $this->__mcBrk->next();
        $this->__mcIndex = $this->__mcIndex + 1;
    }

    public function rewind(): void
    {
        $this->__mcIndex = 0;
        $this->__mcPos = $this->__mcBrk->first();
    }

    public function valid(): bool
    {
        return $this->__mcPos !== -1;
    }
}
