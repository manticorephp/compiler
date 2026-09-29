<?php
/**
 * ext/intl MessageFormatter over ICU's C API. umsg_* formats positional arguments
 * only, php names them: the pattern is scanned here (MessagePattern, apostrophe mode
 * DOUBLE_OPTIONAL), every argument occurrence is renumbered to its own slot and an
 * argument the call does not supply becomes the literal "{name}", as MessageFormat
 * prints it. The slots reach umsg_vformat / umsg_vparse through a va_list built for
 * the target ABI.
 */

#[\Ffi\Library('icui18n'), \Ffi\Symbol('umsg_open')]
function __mc_icu_umsg_open(\Ffi\Ptr $pattern, #[\Ffi\CType('int')] int $len, string $locale,
    \Ffi\Ptr $parseError, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('umsg_clone')]
function __mc_icu_umsg_clone(\Ffi\Ptr $fmt, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('umsg_close')]
function __mc_icu_umsg_close(\Ffi\Ptr $fmt): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('umsg_applyPattern')]
function __mc_icu_umsg_applyPattern(\Ffi\Ptr $fmt, \Ffi\Ptr $pattern, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $parseError, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('umsg_getLocale')]
function __mc_icu_umsg_getLocale(\Ffi\Ptr $fmt): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('umsg_vformat'), \Ffi\CType('int')]
function __mc_icu_umsg_vformat(\Ffi\Ptr $fmt, \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap,
    \Ffi\Ptr $ap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_parseToUFormattable')]
function __mc_icu_unum_parseToUFormattable(\Ffi\Ptr $fmt, \Ffi\Ptr $result, \Ffi\Ptr $text,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $pos, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ufmt_open')]
function __mc_icu_ufmt_open(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ufmt_close')]
function __mc_icu_ufmt_close(\Ffi\Ptr $fmt): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ufmt_getType'), \Ffi\CType('int')]
function __mc_icu_ufmt_getType(\Ffi\Ptr $fmt, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ufmt_getLong'), \Ffi\CType('int')]
function __mc_icu_ufmt_getLong(\Ffi\Ptr $fmt, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ufmt_getInt64'), \Ffi\CType('longlong')]
function __mc_icu_ufmt_getInt64(\Ffi\Ptr $fmt, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ufmt_getDouble'), \Ffi\CType('double')]
function __mc_icu_ufmt_getDouble(\Ffi\Ptr $fmt, \Ffi\Ptr $err): float { return 0.0; }

/** One `{…}` argument of a MessageFormat pattern; offsets are bytes of the pattern. */
final class __McMsgArg
{
    public function __construct(
        public int $start,
        public int $end,
        public int $nameStart,
        public int $nameEnd,
        public string $name,
        public bool $numbered,
        public string $kind,
        public string $type,
        public string $style,
    ) {}

    /** A choice argument's (limit, sub-message) pairs. @var array<int, array{0: float, 1: __McMsgText}> */
    public array $choices = [];

    /** The Formattable type ICU's MessageFormat declares for this occurrence (getArgTypeList). */
    public function icuType(): string
    {
        if ($this->kind === 'none' || $this->kind === 'select') { return 's'; }
        if ($this->kind !== 'simple') { return 'd'; }
        if ($this->type === 'date' || $this->type === 'time') { return 't'; }
        if ($this->type === 'number' && \strtolower(\trim($this->style, " \t\n\r\x0B\x0C")) === 'integer') { return 'l'; }
        return 'd';
    }

    /** The type php's umsg_parse_format declares for a named-argument pattern (exact keywords). */
    public function phpType(): string
    {
        if ($this->kind === 'none' || $this->kind === 'select') { return 's'; }
        if ($this->kind !== 'simple') { return 'd'; }
        if ($this->type === 'date' || $this->type === 'time') { return 't'; }
        if ($this->type === 'number' && $this->style === 'integer') { return 'q'; }
        return 'd';
    }
}

/**
 * A message's text as MessageFormat::parse matches it: the literal pieces (quoting
 * syntax removed) around its direct arguments (indexes into __McMsgScan::$args).
 */
final class __McMsgText
{
    /** @var string[] */
    public array $lits = [""];
    /** @var int[] */
    public array $args = [];

    public function add(string $s): void
    {
        $this->lits[\count($this->lits) - 1] .= $s;
    }
}

/** MessagePattern's argument structure, for a pattern umsg_open already accepted. */
final class __McMsgScan
{
    /** @var __McMsgArg[] */
    public array $args = [];
    public __McMsgText $top;
    public bool $named = false;
    private string $p;
    private int $n;

    public function __construct(string $pattern)
    {
        $this->p = $pattern;
        $this->n = \strlen($pattern);
        $this->top = new __McMsgText();
        $this->message(0, 0, "", $this->top);
    }

    /** Past the Pattern_White_Space at `$i`. */
    private function ws(int $i): int
    {
        $p = $this->p;
        while ($i < $this->n) {
            $c = $p[$i];
            if ($c === " " || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\x0B" || $c === "\x0C") {
                $i = $i + 1;
                continue;
            }
            if ($c === "\xC2" && $i + 1 < $this->n && $p[$i + 1] === "\x85") {
                $i = $i + 2;
                continue;
            }
            if ($c === "\xE2" && $i + 2 < $this->n && $p[$i + 1] === "\x80") {
                $d = $p[$i + 2];
                if ($d === "\x8E" || $d === "\x8F" || $d === "\xA8" || $d === "\xA9") {
                    $i = $i + 3;
                    continue;
                }
            }
            break;
        }
        return $i;
    }

    /** Past the identifier at `$i` (neither Pattern_Syntax nor Pattern_White_Space). */
    private function ident(int $i): int
    {
        while ($i < $this->n) {
            $o = \ord($this->p[$i]);
            if ($o >= 0x80 || ($o >= 0x30 && $o <= 0x39) || ($o >= 0x41 && $o <= 0x5A) || ($o >= 0x61 && $o <= 0x7A) || $o === 0x5F) {
                if ($o >= 0x80 && $this->ws($i) !== $i) { break; }
                $i = $i + 1;
                continue;
            }
            break;
        }
        return $i;
    }

    /** Past the choice/plural number at `$i`. */
    private function double(int $i): int
    {
        $p = $this->p;
        while ($i < $this->n) {
            $c = $p[$i];
            if (($c >= "0" && $c <= "9") || $c === "+" || $c === "-" || $c === "." || $c === "e" || $c === "E") {
                $i = $i + 1;
                continue;
            }
            if ($c === "\xE2" && \substr($p, $i, 3) === "\xE2\x88\x9E") {
                $i = $i + 3;
                continue;
            }
            break;
        }
        return $i;
    }

    /**
     * Message text from `$i`: the index of the `}` (or a choice's `|`) that ends a
     * nested message, else the pattern length.
     */
    private function message(int $i, int $depth, string $parent, ?__McMsgText $text): int
    {
        $p = $this->p;
        $plural = $parent === 'plural' || $parent === 'selectordinal';
        while ($i < $this->n) {
            $c = $p[$i];
            if ($c === "'") {
                $d = $i + 1 < $this->n ? $p[$i + 1] : "";
                if ($d === "'") {
                    $text?->add("'");
                    $i = $i + 2;
                    continue;
                }
                if ($d === "{" || $d === "}" || ($plural && $d === "#") || ($parent === 'choice' && $d === "|")) {
                    $text?->add($d);
                    $i = $i + 2;
                    while ($i < $this->n) {
                        if ($p[$i] === "'") {
                            if ($i + 1 < $this->n && $p[$i + 1] === "'") {
                                $text?->add("'");
                                $i = $i + 2;
                                continue;
                            }
                            $i = $i + 1;
                            break;
                        }
                        $text?->add($p[$i]);
                        $i = $i + 1;
                    }
                    continue;
                }
                $text?->add("'");
                $i = $i + 1;
                continue;
            }
            if ($c === "{") {
                if ($text !== null) {
                    $text->args[] = \count($this->args);
                    $text->lits[] = "";
                }
                $i = $this->arg($i, $depth);
                continue;
            }
            if ($depth > 0 && ($c === "}" || ($parent === 'choice' && $c === "|"))) {
                return $i;
            }
            $text?->add($c);
            $i = $i + 1;
        }
        return $i;
    }

    /** The argument whose `{` is at `$start`; answers the index past its `}`. */
    private function arg(int $start, int $depth): int
    {
        $p = $this->p;
        $ns = $this->ws($start + 1);
        $ne = $this->ident($ns);
        $name = \substr($p, $ns, $ne - $ns);
        $numbered = $name !== "" && \ctype_digit($name);
        if (!$numbered) { $this->named = true; }
        $a = new __McMsgArg($start, 0, $ns, $ne, $name, $numbered, 'none', "", "");
        $this->args[] = $a;
        $i = $this->ws($ne);
        if ($i < $this->n && $p[$i] === ",") {
            $ts = $this->ws($i + 1);
            $te = $ts;
            while ($te < $this->n && \ctype_alpha($p[$te])) { $te = $te + 1; }
            $type = \strtolower(\substr($p, $ts, $te - $ts));
            $i = $this->ws($te);
            $kind = 'simple';
            if ($type === 'choice' || $type === 'plural' || $type === 'select' || $type === 'selectordinal') {
                $kind = $type;
            }
            $a->kind = $kind;
            $a->type = $type;
            if ($i < $this->n && $p[$i] === ",") {
                $i = $i + 1;
                if ($kind === 'simple') {
                    $s = $i;
                    $nest = 0;
                    while ($i < $this->n) {
                        $c = $p[$i];
                        $i = $i + 1;
                        if ($c === "'") {
                            $q = \strpos($p, "'", $i);
                            $i = $q === false ? $this->n : $q + 1;
                        } elseif ($c === "{") {
                            $nest = $nest + 1;
                        } elseif ($c === "}") {
                            if ($nest === 0) {
                                $i = $i - 1;
                                break;
                            }
                            $nest = $nest - 1;
                        }
                    }
                    $a->style = \substr($p, $s, $i - $s);
                } elseif ($kind === 'choice') {
                    $i = $this->ws($i);
                    while ($i < $this->n) {
                        $ds = $i;
                        $i = $this->double($i);
                        $num = \substr($p, $ds, $i - $ds);
                        $limit = $num === "\xE2\x88\x9E" || $num === "+\xE2\x88\x9E" ? INF : ($num === "-\xE2\x88\x9E" ? -INF : (float)$num);
                        $i = $this->ws($i);
                        $i = $i + ($p[$i] === "\xE2" ? 3 : 1);
                        $sub = new __McMsgText();
                        $a->choices[] = [$limit, $sub];
                        $i = $this->message($i, $depth + 1, 'choice', $sub);
                        if ($i >= $this->n || $p[$i] === "}") { break; }
                        $i = $this->ws($i + 1);
                    }
                } else {
                    while (true) {
                        $i = $this->ws($i);
                        if ($i >= $this->n || $p[$i] === "}") { break; }
                        if ($kind !== 'select' && $p[$i] === "=") {
                            $i = $this->double($i + 1);
                        } else {
                            $s = $i;
                            $i = $this->ident($i);
                            if ($kind !== 'select' && $i - $s === 6 && \substr($p, $s, 7) === "offset:") {
                                $i = $this->double($this->ws($i + 1));
                                continue;
                            }
                        }
                        $i = $this->ws($i);
                        $i = $this->message($i + 1, $depth + 1, $kind, null) + 1;
                    }
                }
            }
        }
        $a->end = $i + 1;
        return $i + 1;
    }
}

/** How this target passes a va_list: 1 = a pointer to the slots (Apple arm64), 2 = SysV x86_64, 3 = AAPCS64. */
final class __McMsgVa
{
    public static int $abi = 0;
}

/** A va_list over the 8-byte argument `$slots`; `$hdr` is 32 bytes the callee may consume. */
function __mc_msgfmt_va(\Ffi\Ptr $slots, \Ffi\Ptr $hdr): \Ffi\Ptr
{
    if (__McMsgVa::$abi === 0) {
        $m = \php_uname('m');
        __McMsgVa::$abi = $m === 'x86_64' || $m === 'amd64' ? 2 : (PHP_OS_FAMILY === 'Darwin' ? 1 : 3);
    }
    if (__McMsgVa::$abi === 1) { return $slots; }
    $s = \ptr_to_int($slots);
    if (__McMsgVa::$abi === 2) {
        // gp_offset / fp_offset past the register save area: every argument from overflow_arg_area.
        \poke_i32($hdr, 0, 48);
        \poke_i32($hdr, 4, 176);
        \poke_i64($hdr, 8, $s);
        \poke_i64($hdr, 16, $s);
        return $hdr;
    }
    // __stack, __gr_top, __vr_top, __gr_offs = __vr_offs = 0: every argument from __stack.
    \poke_i64($hdr, 0, $s);
    \poke_i64($hdr, 8, $s);
    \poke_i64($hdr, 16, $s);
    \poke_i32($hdr, 24, 0);
    \poke_i32($hdr, 28, 0);
    return $hdr;
}

/** An argument value converted for its declared type, or its conversion error. */
final class __McMsgValue
{
    public function __construct(public string $type, public mixed $v) {}
}

class MessageFormatter
{
    private int $__mcMain = 0;
    private string $__mcPattern = "";
    private string $__mcLocale = "";
    private bool $__mcTzSet = false;
    private ?string $__mcTz = null;
    private int $__mcFmt = 0;
    private string $__mcFmtKey = "";
    private ?__McMsgScan $__mcScan = null;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    /** Set while create() builds an object whose constructor must stay inert. */
    public static bool $__mcInert = false;

    public function __construct(string $locale, string $pattern)
    {
        if (self::$__mcInert) { return; }
        \__mc_intl_reset();
        $this->__mcInit("MessageFormatter::__construct", true, $locale, $pattern);
    }

    public static function create(string $locale, string $pattern): ?MessageFormatter
    {
        return self::__mcCreate("MessageFormatter::create", $locale, $pattern);
    }

    public static function __mcCreate(string $fn, string $locale, string $pattern): ?MessageFormatter
    {
        \__mc_intl_reset();
        $f = self::__mcBlank();
        return $f->__mcInit($fn, false, $locale, $pattern) ? $f : null;
    }

    public static function __mcBlank(): MessageFormatter
    {
        self::$__mcInert = true;
        $f = new MessageFormatter("", "");
        self::$__mcInert = false;
        return $f;
    }

    /** The static forms' formatter: umsg_open only, the error left to the caller. */
    public function __mcAdopt(string $pattern, string $locale, int &$code, \Ffi\Ptr $pe): bool
    {
        $f = self::__mcOpen($pattern, $locale, $code, $pe);
        if ($f === null) { return false; }
        $this->__mcPattern = $pattern;
        $this->__mcLocale = $locale;
        $this->__mcMain = \ptr_to_int($f);
        return true;
    }

    public function __clone()
    {
        if ($this->__mcMain !== 0) {
            $e = \__mc_icu_err();
            $this->__mcMain = \ptr_to_int(\__mc_icu_umsg_clone(\int_to_ptr($this->__mcMain), $e));
            \__mc_icu_free($e);
        }
        $this->__mcTzSet = false;
        $this->__mcFmt = 0;
        $this->__mcFmtKey = "";
    }

    public function __destruct()
    {
        if ($this->__mcMain !== 0) {
            \__mc_icu_umsg_close(\int_to_ptr($this->__mcMain));
            $this->__mcMain = 0;
        }
        $this->__mcDropFmt();
    }

    private function __mcDropFmt(): void
    {
        if ($this->__mcFmt !== 0) {
            \__mc_icu_umsg_close(\int_to_ptr($this->__mcFmt));
            $this->__mcFmt = 0;
        }
        $this->__mcFmtKey = "";
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __mcReset(): void
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    /** intl_errors_set: the object's error and the global one. */
    public function __mcFail(string $fn, string $what, int $code): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    /**
     * umsg_open, or null with the ICU code in `$code` and the UParseError in `$pe`
     * (when given).
     */
    public static function __mcOpen(string $pattern, string $locale, int &$code, ?\Ffi\Ptr $pe): ?\Ffi\Ptr
    {
        $u = \__mc_icu_to16($pattern);
        if ($u === null) {
            $code = 10;
            return null;
        }
        $e = \__mc_icu_err();
        $own = $pe === null;
        if ($own) { $pe = \__mc_icu_malloc(72); }
        $f = \__mc_icu_umsg_open($u->buf, $u->len, $locale, $pe, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($own) { \__mc_icu_free($pe); }
        \__mc_icu_free($u->buf);
        if ($code > 0) {
            if (\ptr_to_int($f) !== 0) { \__mc_icu_umsg_close($f); }
            return null;
        }
        return $f;
    }

    /** msgfmt_ctor. */
    public function __mcInit(string $fn, bool $throw, string $locale, string $pattern): bool
    {
        if (\strlen($locale) > 156) {
            return $this->__mcCtorFail($fn, $throw, 1, "Locale string too long, should be no longer than 156 characters");
        }
        if ($pattern !== "" && !\__mc_msgfmt_utf8($pattern)) {
            return $this->__mcCtorFail($fn, $throw, 10, "error converting pattern to UTF-16");
        }
        $this->__mcLocale = $locale === "" ? \__mc_intl_default_locale() : $locale;
        $this->__mcPattern = $pattern;
        if ($pattern === "") {
            return $this->__mcCtorFail($fn, $throw, 1, "message formatter creation failed");
        }
        $pe = \__mc_icu_malloc(72);
        $code = 0;
        $f = self::__mcOpen($pattern, $this->__mcLocale, $code, $pe);
        if ($f === null) {
            $what = $code === 65799
                ? "pattern syntax error (" . \__mc_icu_parse_error_string($pe) . ")"
                : "message formatter creation failed";
            \__mc_icu_free($pe);
            return $this->__mcCtorFail($fn, $throw, $code, $what);
        }
        \__mc_icu_free($pe);
        $this->__mcMain = \ptr_to_int($f);
        return true;
    }

    private function __mcCtorFail(string $fn, bool $throw, int $code, string $what): bool
    {
        $this->__mcFail($fn, $what, $code);
        if ($throw) {
            throw new IntlException($fn . "(): " . $what);
        }
        return false;
    }

    public function __mcScan(): __McMsgScan
    {
        if ($this->__mcScan === null) { $this->__mcScan = new __McMsgScan($this->__mcPattern); }
        return $this->__mcScan;
    }

    /**
     * umsg_get_types: argument key => declared type ('s' string, 'd' double, 'l' int32,
     * 'q' int64, 't' date), or null with the error set. Numbered patterns take ICU's
     * own list; a named one php's exact-keyword reading. Keys: "#N" for a number.
     * @return array<string, string>|null
     */
    private function __mcTypes(string $fn): ?array
    {
        $scan = $this->__mcScan();
        $types = [];
        foreach ($scan->args as $a) {
            $key = $a->numbered ? "#" . (string)(int)$a->name : $a->name;
            $t = $scan->named ? $a->phpType() : $a->icuType();
            if (isset($types[$key]) && $types[$key] !== $t) {
                $this->__mcFail($fn, "Inconsistent types declared for an argument", 65804);
                return null;
            }
            $types[$key] = $t;
        }
        return $types;
    }

    /**
     * umsg_format_helper's conversions: argument key => converted value, or null with
     * the (first) error set.
     * @param array<mixed> $args
     * @return array<string, __McMsgValue>|null
     */
    private function __mcConvert(string $fn, array $args): ?array
    {
        $types = $this->__mcTypes($fn);
        if ($types === null) { return null; }
        $out = [];
        foreach ($args as $k => $v) {
            if (\is_int($k)) {
                if ($k < 0 || $k > 2147483647) {
                    $this->__mcFail($fn, "Found negative or too large array key", 1);
                    return null;
                }
                $name = (string)$k;
                $key = "#" . $name;
            } else {
                if (!\__mc_msgfmt_utf8($k)) {
                    $this->__mcFail($fn, "Invalid UTF-8 data in argument key: '" . $k . "'", 10);
                    return null;
                }
                $name = $k;
                $key = $k;
            }
            $t = $types[$key] ?? "";
            if ($t === "") {
                if (\is_float($v)) {
                    $out[$key] = new __McMsgValue('d', $v);
                } elseif (\is_int($v)) {
                    $out[$key] = new __McMsgValue('q', $v);
                } elseif ($v === null || $v === false) {
                    $out[$key] = new __McMsgValue('q', 0);
                } elseif ($v === true) {
                    $out[$key] = new __McMsgValue('q', 1);
                } elseif (\is_string($v) || \is_object($v)) {
                    $t = 's';
                } else {
                    $this->__mcFail($fn, "No strategy to convert the value given for the argument with key '" . $name . "' is available", 1);
                    return null;
                }
                if ($t === "") { continue; }
            }
            if ($t === 's') {
                $s = \is_array($v) ? "Array" : (string)$v;
                if (!\__mc_msgfmt_utf8($s)) {
                    $this->__mcFail($fn, "Invalid UTF-8 data in string argument: '" . $s . "'", 10);
                    return null;
                }
                $out[$key] = new __McMsgValue('s', $s);
            } elseif ($t === 'd') {
                $out[$key] = new __McMsgValue('d', \__mc_msgfmt_double($v));
            } elseif ($t === 'l') {
                if (\is_float($v)) {
                    if ($v > 2147483647.0 || $v < -2147483648.0) {
                        $this->__mcFail($fn, "Found PHP float with absolute value too large for 32 bit integer argument", 1);
                        return null;
                    }
                    $i = (int)$v;
                } elseif (\is_int($v)) {
                    if ($v > 2147483647 || $v < -2147483648) {
                        $this->__mcFail($fn, "Found PHP integer with absolute value too large for 32 bit integer argument", 1);
                        return null;
                    }
                    $i = $v;
                } else {
                    $i = \__mc_msgfmt_long($v);
                    $i = $i & 0xFFFFFFFF;
                    if ($i > 2147483647) { $i = $i - 4294967296; }
                }
                $out[$key] = new __McMsgValue('l', $i);
            } elseif ($t === 'q') {
                if (\is_float($v)) {
                    if ($v > 18446744073709551615.0 || $v < -9223372036854775808.0) {
                        $this->__mcFail($fn, "Found PHP float with absolute value too large for 64 bit integer argument", 1);
                        return null;
                    }
                    $i = (int)$v;
                } elseif (\is_int($v)) {
                    $i = $v;
                } else {
                    $i = \__mc_msgfmt_long($v);
                }
                $out[$key] = new __McMsgValue('q', $i);
            } else {
                $err = new __McIntlErrorBox();
                $ms = \__mc_datefmt_millis($v, $err);
                if ($ms === null) {
                    $this->__mcFail($fn, "The argument for key '" . $name . "' cannot be used as a date or time", $err->code);
                    return null;
                }
                $out[$key] = new __McMsgValue('t', $ms);
            }
        }
        return $out;
    }

    /** msgfmt_do_format: the formatted message, or false with the error set. */
    public function __mcFormat(string $fn, array $args): string|false
    {
        $values = $this->__mcConvert($fn, $args);
        if ($values === null) { return false; }
        if (!$this->__mcTzSet) {
            // umsg_set_timezone: the TOP-LEVEL date/time subformats take php's default
            // zone, once; a nested one (inside a plural/select) keeps ICU's default.
            $this->__mcTzSet = true;
            $this->__mcTz = \__mc_intlcal_default_zone();
        }
        $scan = $this->__mcScan();
        $top = [];
        foreach ($scan->top->args as $ai) { $top[$ai] = true; }
        $p = $this->__mcPattern;
        $out = "";
        $at = 0;
        $skipTo = -1;
        /** @var __McMsgValue[] $slots */
        $slots = [];
        /** @var string[] $slotTypes */
        $slotTypes = [];
        $nul = [];
        foreach ($scan->args as $ai => $a) {
            if ($a->start < $skipTo) { continue; }
            $key = $a->numbered ? "#" . (string)(int)$a->name : $a->name;
            $val = $values[$key] ?? null;
            $k = \count($slots);
            if ($val === null) {
                // MessageFormat prints an argument it was not given as "{name}";
                // a string slot carries that text (a quoted literal could fuse
                // with the pattern's own quoting).
                $slots[] = new __McMsgValue('s', "{" . $a->name . "}");
                $slotTypes[] = 's';
                $out .= \substr($p, $at, $a->start - $at) . "{" . (string)$k . "}";
                $at = $a->end;
                $skipTo = $a->end;
                continue;
            }
            $t = $a->icuType();
            if ($t === 't' && $this->__mcTz !== null && isset($top[$ai])) {
                // A date in php's zone: formatted here by the same DateFormat MessageFormat builds.
                $s = \__mc_msgfmt_date_text($a, $this->__mcLocale, $this->__mcTz, (float)$val->v);
                if ($s === null) {
                    $this->__mcFail($fn, "Call to ICU MessageFormat::format() has failed", 1);
                    return false;
                }
                $slots[] = new __McMsgValue('s', $s);
                $slotTypes[] = 's';
                $out .= \substr($p, $at, $a->start - $at) . "{" . (string)$k . "}";
                $at = $a->end;
                $skipTo = $a->end;
                continue;
            }
            if ($t === 'l' && !($val->type === 'l' || ($val->type === 'q' && $val->v >= -2147483648 && $val->v <= 2147483647))) {
                // An int32 slot cannot hold it: php formats the int64/double with the
                // same integer NumberFormat, so format it here and pass the text.
                $slots[] = new __McMsgValue('s', \__mc_msgfmt_integer($this->__mcLocale, $val));
                $slotTypes[] = 's';
                $out .= \substr($p, $at, $a->start - $at) . "{" . (string)$k . "}";
                $at = $a->end;
                $skipTo = $a->end;
                continue;
            }
            $slots[] = $val;
            $slotTypes[] = $t;
            if ($t === 's' && \str_contains((string)$val->v, "\0")) { $nul[$k] = true; }
            $out .= \substr($p, $at, $a->nameStart - $at) . (string)$k;
            $at = $a->nameEnd;
        }
        $out .= \substr($p, $at);
        if ($out !== $this->__mcFmtKey) {
            $this->__mcDropFmt();
            $code = 0;
            $f = self::__mcOpen($out, $this->__mcLocale, $code, null);
            if ($f === null) {
                $this->__mcFail($fn, "Call to ICU MessageFormat::format() has failed", $code);
                return false;
            }
            $this->__mcFmt = \ptr_to_int($f);
            $this->__mcFmtKey = $out;
        }
        return $this->__mcRun($fn, $slots, $slotTypes, $nul);
    }

    /**
     * umsg_vformat over the renumbered slots. A string holding NUL cannot cross the C
     * API: it rides as a private-use token replaced in the result.
     * @param __McMsgValue[] $slots
     * @param string[] $types
     * @param array<int, bool> $nul
     */
    private function __mcRun(string $fn, array $slots, array $types, array $nul): string|false
    {
        $n = \count($slots);
        $mem = \__mc_icu_malloc($n * 8 + 8);
        $hdr = \__mc_icu_malloc(32);
        $bufs = [];
        $tokens = [];
        $mark = "";
        if (\count($nul) > 0) {
            $mark = "\u{F8FF}";
            foreach (["\u{F8FF}", "\u{F8FE}", "\u{F8FD}", "\u{F8FC}", "\u{F8FB}"] as $cand) {
                $clash = \str_contains($this->__mcPattern, $cand);
                foreach ($slots as $s) {
                    if ($s->type === 's' && \str_contains((string)$s->v, $cand)) { $clash = true; }
                }
                if (!$clash) {
                    $mark = $cand;
                    break;
                }
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $v = $slots[$i]->v;
            $t = $types[$i];
            if ($t === 's') {
                $s = (string)$v;
                if (isset($nul[$i])) {
                    $tokens[$mark . (string)$i . $mark] = $s;
                    $s = $mark . (string)$i . $mark;
                }
                $u = \__mc_icu_to16($s);
                $bufs[] = $u->buf;
                \poke_i64($mem, $i * 8, \ptr_to_int($u->buf));
            } elseif ($t === 'l') {
                \poke_i64($mem, $i * 8, (int)$v);
            } else {
                \poke_f64($mem, $i * 8, (float)$v);
            }
        }
        $fmt = \int_to_ptr($this->__mcFmt);
        $res = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
            => \__mc_icu_umsg_vformat($fmt, $b, $c, \__mc_msgfmt_va($mem, $hdr), $e));
        foreach ($bufs as $b) { \__mc_icu_free($b); }
        \__mc_icu_free($mem);
        \__mc_icu_free($hdr);
        if ($res === null) {
            $this->__mcFail($fn, "Call to ICU MessageFormat::format() has failed", __McIcuStatus::$code);
            return false;
        }
        \__mc_intl_reset_code();
        return \count($tokens) > 0 ? \strtr($res, $tokens) : $res;
    }

    /** msgfmt_do_parse. @return array<int, int|float|string>|false */
    public function __mcParse(string $fn, string $string): array|false
    {
        $src = \__mc_icu_to16($string);
        if ($src === null) {
            $this->__mcFail($fn, "Converting parse string failed", 10);
            return false;
        }
        $scan = $this->__mcScan();
        $res = $scan->named ? null : \__mc_msgfmt_parse($scan, $string, $src, $this->__mcLocale, $this->__mcTz);
        \__mc_icu_free($src->buf);
        if ($res === null) {
            // MessageFormat::parse: U_ARGUMENT_TYPE_MISMATCH for named arguments, else U_MESSAGE_PARSE_ERROR.
            $this->__mcFail($fn, "Parsing failed", $scan->named ? 65804 : 6);
            return false;
        }
        \__mc_intl_reset_code();
        return $res;
    }

    /** msgfmt_set_pattern. */
    public function __mcSetPattern(string $fn, string $pattern): bool
    {
        $u = \__mc_icu_to16($pattern);
        if ($u === null) {
            $this->__mcFail($fn, "Error converting pattern to UTF-16", 10);
            return false;
        }
        $pe = \__mc_icu_malloc(72);
        \poke_i32($pe, 0, 0);
        \poke_i32($pe, 4, 0);
        $e = \__mc_icu_err();
        \__mc_icu_umsg_applyPattern(\int_to_ptr($this->__mcMain), $u->buf, $u->len, $pe, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        \__mc_icu_free($u->buf);
        $line = \peek_i32($pe, 0);
        $off = \peek_i32($pe, 4);
        \__mc_icu_free($pe);
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \intl_error_name($code);
        if ($code > 0) {
            // intl_errors_set_custom_msg: the message only, the codes stay as they were.
            $what = "Error setting symbol value at line " . (string)$line . ", offset " . (string)$off;
            $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
            __McIntlError::$message = $fn . "(): " . $what;
            return false;
        }
        $this->__mcPattern = $pattern;
        $this->__mcScan = null;
        // A re-applied pattern builds its subformats on ICU's default zone; php's is not re-applied.
        $this->__mcTz = null;
        $this->__mcDropFmt();
        return true;
    }

    public function format(array $values): string|false
    {
        $this->__mcReset();
        return $this->__mcFormat("MessageFormatter::format", $values);
    }

    public static function formatMessage(string $locale, string $pattern, array $values): string|false
    {
        $f = \__mc_msgfmt_temp("MessageFormatter::formatMessage", $locale, $pattern);
        return $f === null ? false : $f->__mcFormat("MessageFormatter::formatMessage", $values);
    }

    /** @return array<int, int|float|string>|false */
    public function parse(string $string): array|false
    {
        $this->__mcReset();
        return $this->__mcParse("MessageFormatter::parse", $string);
    }

    /** @return array<int, int|float|string>|false */
    public static function parseMessage(string $locale, string $pattern, string $message): array|false
    {
        $f = \__mc_msgfmt_temp("MessageFormatter::parseMessage", $locale, $pattern, true);
        return $f === null ? false : $f->__mcParse("MessageFormatter::parseMessage", $message);
    }

    public function setPattern(string $pattern): bool
    {
        $this->__mcReset();
        return $this->__mcSetPattern("MessageFormatter::setPattern", $pattern);
    }

    public function getPattern(): string|false
    {
        $this->__mcReset();
        return $this->__mcPattern;
    }

    public function getLocale(): string
    {
        $this->__mcReset();
        return $this->__mcLocaleText();
    }

    public function getErrorCode(): int
    {
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): string
    {
        return $this->__mcErrMessage();
    }
    public function __mcLocaleText(): string
    {
        return \cstr_to_str(\__mc_icu_umsg_getLocale(\int_to_ptr($this->__mcMain)));
    }

    public function __mcErrMessage(): string
    {
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }
}

/** INTL_METHOD_CHECK_STATUS on success: the global code follows the object's, the message stays. */
function __mc_intl_reset_code(): void
{
    __McIntlError::$code = 0;
}

/** Whether `$s` is well-formed UTF-8, as intl's conversion to UTF-16 decides it. */
function __mc_msgfmt_utf8(string $s): bool
{
    $u = \__mc_icu_to16($s);
    if ($u === null) { return false; }
    \__mc_icu_free($u->buf);
    return true;
}

/** zval_get_double. */
function __mc_msgfmt_double(mixed $v): float
{
    if (\is_array($v)) { return \count($v) > 0 ? 1.0 : 0.0; }
    if (\is_object($v)) { return 1.0; }
    return (float)$v;
}

/** zval_get_long. */
function __mc_msgfmt_long(mixed $v): int
{
    if (\is_array($v)) { return \count($v) > 0 ? 1 : 0; }
    if (\is_object($v)) { return 1; }
    return (int)$v;
}

/** createIntegerFormat: the locale's decimal format without fraction digits, applied to `$v`. */
function __mc_msgfmt_integer(string $locale, __McMsgValue $v): string
{
    $e = \__mc_icu_err();
    $f = \__mc_icu_unum_open(1, \int_to_ptr(0), 0, $locale, \int_to_ptr(0), $e);
    \__mc_icu_free($e);
    // UNUM_MAX_FRACTION_DIGITS, UNUM_DECIMAL_ALWAYS_SHOWN, UNUM_PARSE_INT_ONLY
    \__mc_icu_unum_setAttribute($f, 6, 0);
    \__mc_icu_unum_setAttribute($f, 2, 0);
    \__mc_icu_unum_setAttribute($f, 0, 1);
    $x = $v->v;
    $out = \is_int($x)
        ? \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_formatInt64($f, $x, $b, $c, \int_to_ptr(0), $e))
        : \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_formatDouble($f, (float)$x, $b, $c, \int_to_ptr(0), $e));
    \__mc_icu_unum_close($f);
    return $out ?? "";
}

/** The UTF-16 index of byte `$byte` of the UTF-8 `$s`. */
function __mc_msgfmt_u16_index(string $s, int $byte): int
{
    $u = 0;
    for ($i = 0; $i < $byte; $i++) {
        $o = \ord($s[$i]);
        if ($o < 0x80 || $o >= 0xC0) { $u = $u + ($o >= 0xF0 ? 2 : 1); }
    }
    return $u;
}

/** The byte offset of UTF-16 index `$u16` of the UTF-8 `$s`. */
function __mc_msgfmt_byte_offset(string $s, int $u16): int
{
    $n = \strlen($s);
    $u = 0;
    $i = 0;
    while ($i < $n && $u < $u16) {
        $o = \ord($s[$i]);
        $w = $o < 0x80 ? 1 : ($o < 0xE0 ? 2 : ($o < 0xF0 ? 3 : 4));
        $u = $u + ($w === 4 ? 2 : 1);
        $i = $i + $w;
    }
    return $i;
}

/**
 * MessageFormat::parse over the top-level message: its literals must match, a plain
 * argument takes the text up to the next literal, a choice the sub-message matching
 * furthest, a formatted one what its subformat parses; plural/select cannot parse.
 * Arguments below the highest one parsed but without a result are int 0 (a default
 * Formattable). Null when the source does not match.
 * @return array<int, int|float|string>|null
 */
function __mc_msgfmt_parse(__McMsgScan $scan, string $s, __McIcuU16 $src, string $locale, ?string $tz): ?array
{
    $top = $scan->top;
    $n = \strlen($s);
    $pos = 0;
    $vals = [];
    $count = 0;
    foreach ($top->args as $j => $ai) {
        $lit = $top->lits[$j];
        $len = \strlen($lit);
        if ($len > 0 && ($pos + $len > $n || \substr($s, $pos, $len) !== $lit)) { return null; }
        $pos = $pos + $len;
        $a = $scan->args[$ai];
        $num = (int)$a->name;
        if ($a->kind === 'none') {
            $after = $top->lits[$j + 1];
            $next = $after === "" ? $n : \strpos($s, $after, $pos);
            if ($next === false) { return null; }
            $v = \substr($s, $pos, $next - $pos);
            if ($v !== "{" . (string)$num . "}") {
                $vals[$num] = $v;
                if ($count <= $num) { $count = $num + 1; }
            }
            $pos = $next;
            continue;
        }
        if ($a->kind === 'choice') {
            $best = NAN;
            $furthest = $pos;
            foreach ($a->choices as $ch) {
                $sub = $ch[1];
                if (\count($sub->args) > 0) { continue; }
                $l = $sub->lits[0];
                $ll = \strlen($l);
                if ($pos + $ll <= $n && \substr($s, $pos, $ll) === $l && $pos + $ll > $furthest) {
                    $furthest = $pos + $ll;
                    $best = $ch[0];
                    if ($furthest === $n) { break; }
                }
            }
            if ($furthest === $pos) { return null; }
            $vals[$num] = $best;
            if ($count <= $num) { $count = $num + 1; }
            $pos = $furthest;
            continue;
        }
        if ($a->kind !== 'simple') { return null; }
        $at = \__mc_msgfmt_u16_index($s, $pos);
        $end = $at;
        $v = \__mc_msgfmt_parse_simple($a, $locale, $tz, $src, $at, $end);
        if ($v === null || $end === $at) { return null; }
        $vals[$num] = $v;
        if ($count <= $num) { $count = $num + 1; }
        $pos = \__mc_msgfmt_byte_offset($s, $end);
    }
    $lit = $top->lits[\count($top->lits) - 1];
    $len = \strlen($lit);
    if ($len > 0 && ($pos + $len > $n || \substr($s, $pos, $len) !== $lit)) { return null; }
    if ($pos + $len === 0) { return null; }
    $out = [];
    for ($i = 0; $i < $count; $i++) { $out[] = $vals[$i] ?? 0; }
    return $out;
}

/**
 * The DateFormat MessageFormat::createAppropriateFormat builds for a date/time
 * argument (a style keyword, a `::` skeleton, else a pattern) on zone `$tz` (ICU's
 * default when null), or null with the error in `$e`.
 */
function __mc_msgfmt_udat(__McMsgArg $a, string $locale, ?string $tz, \Ffi\Ptr $e): ?\Ffi\Ptr
{
    $style = \__mc_msgfmt_keyword($a->style);
    $bare = \ltrim($a->style, " \t\n\r\x0B\x0C");
    $styles = ["" => 2, "short" => 3, "medium" => 2, "long" => 1, "full" => 0];
    $pat = null;
    $ds = -2;
    $ts = -2;
    if (isset($styles[$style])) {
        $ds = $a->type === 'date' ? $styles[$style] : -1;
        $ts = $a->type === 'date' ? -1 : $styles[$style];
    } elseif (\str_starts_with($bare, "::")) {
        $sk16 = \__mc_icu_to16(\substr($bare, 2));
        $gen = \__mc_icu_udatpg_open($locale, $e);
        $best = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $err): int
            => \__mc_icu_udatpg_getBestPattern($gen, $sk16->buf, $sk16->len, $b, $c, $err));
        \__mc_icu_free($sk16->buf);
        \__mc_icu_udatpg_close($gen);
        $pat = \__mc_icu_to16($best ?? "");
    } else {
        $pat = \__mc_icu_to16($a->style);
    }
    $z = $tz === null ? null : \__mc_icu_to16($tz);
    $f = \__mc_icu_udat_open($ts, $ds, $locale, $z === null ? \int_to_ptr(0) : $z->buf, $z === null ? 0 : $z->len,
        $pat === null ? \int_to_ptr(0) : $pat->buf, $pat === null ? 0 : $pat->len, $e);
    if ($z !== null) { \__mc_icu_free($z->buf); }
    if ($pat !== null) { \__mc_icu_free($pat->buf); }
    return \peek_i32($e, 0) > 0 ? null : $f;
}

/** A top-level date/time argument formatted in `$tz`, or null. */
function __mc_msgfmt_date_text(__McMsgArg $a, string $locale, string $tz, float $ms): ?string
{
    $e = \__mc_icu_err();
    $f = \__mc_msgfmt_udat($a, $locale, $tz, $e);
    \__mc_icu_free($e);
    if ($f === null) { return null; }
    $s = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $err): int
        => \__mc_icu_udat_format($f, $ms, $b, $c, \int_to_ptr(0), $err));
    \__mc_icu_udat_close($f);
    return $s;
}

/** Pattern_White_Space-trimmed, lowercased: MessageFormat's keyword comparison. */
function __mc_msgfmt_keyword(string $s): string
{
    return \strtolower(\trim($s, " \t\n\r\x0B\x0C"));
}

/**
 * The subformat MessageFormat::createAppropriateFormat builds for `$a` parses the
 * source at UTF-16 index `$at`: its value (a date in seconds) with the index past it
 * in `$end`, or null.
 */
function __mc_msgfmt_parse_simple(__McMsgArg $a, string $locale, ?string $tz, __McIcuU16 $src, int $at, int &$end): int|float|null
{
    $style = \__mc_msgfmt_keyword($a->style);
    $bare = \ltrim($a->style, " \t\n\r\x0B\x0C");
    $skeleton = \str_starts_with($bare, "::");
    $e = \__mc_icu_err();
    $pp = \__mc_icu_malloc(8);
    \poke_i32($pp, 0, $at);
    if ($a->type === 'date' || $a->type === 'time') {
        $f = \__mc_msgfmt_udat($a, $locale, $tz, $e);
        $v = null;
        if ($f !== null) {
            $ms = \__mc_icu_udat_parse($f, $src->buf, $src->len, $pp, $e);
            if (\peek_i32($e, 0) <= 0) {
                $v = $ms / 1000.0;
                $end = \peek_i32($pp, 0);
            }
            \__mc_icu_udat_close($f);
        }
        \__mc_icu_free($pp);
        \__mc_icu_free($e);
        return $v;
    }
    $kind = $a->type === 'spellout' ? 5 : ($a->type === 'ordinal' ? 6 : ($a->type === 'duration' ? 7 : 1));
    if ($kind === 1 && $style !== "" && $style !== "currency" && $style !== "percent" && $style !== "integer" && $skeleton) {
        // A skeleton's LocalizedNumberFormatterAsFormat does not parse.
        \__mc_icu_free($pp);
        \__mc_icu_free($e);
        return null;
    }
    if ($kind === 1 && $style === "currency") { $kind = 2; }
    if ($kind === 1 && $style === "percent") { $kind = 3; }
    $f = \__mc_icu_unum_open($kind, \int_to_ptr(0), 0, $locale, \int_to_ptr(0), $e);
    if ($kind === 1 && $style === "integer") {
        // createIntegerFormat: UNUM_MAX_FRACTION_DIGITS 0, UNUM_DECIMAL_ALWAYS_SHOWN off, UNUM_PARSE_INT_ONLY on.
        \__mc_icu_unum_setAttribute($f, 6, 0);
        \__mc_icu_unum_setAttribute($f, 2, 0);
        \__mc_icu_unum_setAttribute($f, 0, 1);
    } elseif ($kind === 1 && $style !== "") {
        $p16 = \__mc_icu_to16($a->style);
        \__mc_icu_unum_applyPattern($f, 0, $p16->buf, $p16->len, \int_to_ptr(0), $e);
        \__mc_icu_free($p16->buf);
    } elseif ($kind >= 5 && $style !== "") {
        $r16 = \__mc_icu_to16(\trim($a->style, " \t\n\r\x0B\x0C"));
        \__mc_icu_unum_setTextAttribute($f, 6, $r16->buf, $r16->len, $e);
        \__mc_icu_free($r16->buf);
    }
    $v = null;
    if (\peek_i32($e, 0) <= 0) {
        $u = \__mc_icu_ufmt_open($e);
        \__mc_icu_unum_parseToUFormattable($f, $u, $src->buf, $src->len, $pp, $e);
        if (\peek_i32($e, 0) <= 0) {
            // UFMT_LONG = 2, UFMT_INT64 = 5, else a double.
            $type = \__mc_icu_ufmt_getType($u, $e);
            if ($type === 2) {
                $v = \__mc_icu_ufmt_getLong($u, $e);
            } elseif ($type === 5) {
                $v = \__mc_icu_ufmt_getInt64($u, $e);
                if ($v === PHP_INT_MIN) { $v = (float)$v; }
            } else {
                $v = \__mc_icu_ufmt_getDouble($u, $e);
            }
            $end = \peek_i32($pp, 0);
        }
        \__mc_icu_ufmt_close($u);
    }
    \__mc_icu_unum_close($f);
    \__mc_icu_free($pp);
    \__mc_icu_free($e);
    return $v;
}

function msgfmt_create(string $locale, string $pattern): ?MessageFormatter
{
    return MessageFormatter::__mcCreate("msgfmt_create", $locale, $pattern);
}

/**
 * A throwaway formatter for the static forms: an opening error goes to the global only
 * (the parse form reports every one as a plain status failure).
 */
function __mc_msgfmt_temp(string $fn, string $locale, string $pattern, bool $parse = false): ?MessageFormatter
{
    if (\strlen($locale) > 156) {
        \__mc_intl_fail($fn, "Locale string too long, should be no longer than 156 characters", 1);
        return null;
    }
    if ($pattern !== "" && !\__mc_msgfmt_utf8($pattern)) {
        \__mc_intl_fail($fn, "error converting pattern to UTF-16", 1);
        return null;
    }
    $f = MessageFormatter::__mcBlank();
    $code = 1;
    $pe = \__mc_icu_malloc(72);
    $ok = $pattern !== "" && $f->__mcAdopt($pattern, $locale === "" ? \__mc_intl_default_locale() : $locale, $code, $pe);
    if (!$ok) {
        if ($parse) {
            \__mc_intl_fail($fn, "Creating message formatter failed", $code);
        } elseif ($code === 65799) {
            \__mc_intl_fail($fn, "pattern syntax error (" . \__mc_icu_parse_error_string($pe) . ")", $code);
        } else {
            // intl_errors_set_custom_msg: the global code stays what it was.
            __McIntlError::$message = $fn . "(): Creating message formatter failed";
        }
        \__mc_icu_free($pe);
        return null;
    }
    \__mc_icu_free($pe);
    return $f;
}

function msgfmt_format(MessageFormatter $formatter, array $values): string|false
{
    $formatter->__mcReset();
    return $formatter->__mcFormat("msgfmt_format", $values);
}

function msgfmt_format_message(string $locale, string $pattern, array $values): string|false
{
    $f = \__mc_msgfmt_temp("msgfmt_format_message", $locale, $pattern);
    return $f === null ? false : $f->__mcFormat("msgfmt_format_message", $values);
}

/** @return array<int, int|float|string>|false */
function msgfmt_parse(MessageFormatter $formatter, string $string): array|false
{
    $formatter->__mcReset();
    return $formatter->__mcParse("msgfmt_parse", $string);
}

/** @return array<int, int|float|string>|false */
function msgfmt_parse_message(string $locale, string $pattern, string $message): array|false
{
    $f = \__mc_msgfmt_temp("msgfmt_parse_message", $locale, $pattern, true);
    return $f === null ? false : $f->__mcParse("msgfmt_parse_message", $message);
}

function msgfmt_set_pattern(MessageFormatter $formatter, string $pattern): bool
{
    $formatter->__mcReset();
    return $formatter->__mcSetPattern("msgfmt_set_pattern", $pattern);
}

function msgfmt_get_pattern(MessageFormatter $formatter): string|false
{
    return $formatter->getPattern();
}

function msgfmt_get_locale(MessageFormatter $formatter): string
{
    return $formatter->getLocale();
}

function msgfmt_get_error_code(MessageFormatter $formatter): int
{
    return $formatter->getErrorCode();
}

function msgfmt_get_error_message(MessageFormatter $formatter): string
{
    return $formatter->getErrorMessage();
}
