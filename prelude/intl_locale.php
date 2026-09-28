<?php

/**
 * ext/intl Locale over ICU's uloc_* (php-src ext/intl/locale/locale_methods.cpp,
 * transcribed — grandfathered tags, singleton handling and all). Needs
 * prelude/intl.php.
 */

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getLanguage'), \Ffi\CType('int')]
function __mc_icu_uloc_getLanguage(string $loc, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getScript'), \Ffi\CType('int')]
function __mc_icu_uloc_getScript(string $loc, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getCountry'), \Ffi\CType('int')]
function __mc_icu_uloc_getCountry(string $loc, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getVariant'), \Ffi\CType('int')]
function __mc_icu_uloc_getVariant(string $loc, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getDisplayLanguage'), \Ffi\CType('int')]
function __mc_icu_uloc_getDisplayLanguage(string $loc, string $disp, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getDisplayScript'), \Ffi\CType('int')]
function __mc_icu_uloc_getDisplayScript(string $loc, string $disp, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getDisplayCountry'), \Ffi\CType('int')]
function __mc_icu_uloc_getDisplayCountry(string $loc, string $disp, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getDisplayVariant'), \Ffi\CType('int')]
function __mc_icu_uloc_getDisplayVariant(string $loc, string $disp, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getDisplayName'), \Ffi\CType('int')]
function __mc_icu_uloc_getDisplayName(string $loc, string $disp, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_openKeywords')]
function __mc_icu_uloc_openKeywords(string $loc, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getKeywordValue'), \Ffi\CType('int')]
function __mc_icu_uloc_getKeywordValue(string $loc, string $kw, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_isRightToLeft'), \Ffi\CType('char')]
function __mc_icu_uloc_isRightToLeft(string $loc): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_addLikelySubtags'), \Ffi\CType('int')]
function __mc_icu_uloc_addLikelySubtags(string $loc, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_minimizeSubtags'), \Ffi\CType('int')]
function __mc_icu_uloc_minimizeSubtags(string $loc, \Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_openAvailableLocales')]
function __mc_icu_ures_openAvailableLocales(\Ffi\Ptr $path, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_acceptLanguageFromHTTP'), \Ffi\CType('int')]
function __mc_icu_uloc_acceptLanguageFromHTTP(\Ffi\Ptr $buf, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $outResult,
    string $accept, \Ffi\Ptr $available, \Ffi\Ptr $err): int { return 0; }

/** The grandfathered tags and, index for index, their preferred replacements ("" = none). */
final class __McLocaleTags
{
    /** @var string[] */
    public const GRANDFATHERED = ["art-lojban", "cel-gaulish", "en-GB-oed", "i-ami", "i-bnn", "i-default", "i-enochian",
        "i-hak", "i-klingon", "i-lux", "i-mingo", "i-navajo", "i-pwn", "i-tao", "i-tay", "i-tsu", "no-bok", "no-nyn",
        "sgn-BE-FR", "sgn-BE-NL", "sgn-BR", "sgn-CH-DE", "sgn-CO", "sgn-DE", "sgn-DK", "sgn-ES", "sgn-FR", "sgn-GB",
        "sgn-GR", "sgn-IE", "sgn-IT", "sgn-JP", "sgn-MX", "sgn-NI", "sgn-NL", "sgn-NO", "sgn-PT", "sgn-SE", "sgn-US",
        "sgn-ZA", "zh-cmn", "zh-cmn-Hans", "zh-cmn-Hant", "zh-gan", "zh-guoyu", "zh-hakka", "zh-min", "zh-min-nan",
        "zh-wuu", "zh-xiang"];
    /** @var string[] */
    public const PREFERRED = ["jbo", "", "en-GB-oxendict", "ami", "bnn", "", "", "hak", "tlh", "lb", "", "nv", "pwn",
        "tao", "tay", "tsu", "nb", "nn", "sfb", "vgt", "bzs", "sgg", "csn", "gsg", "dsl", "ssp", "fsl", "bfi", "gss",
        "isg", "ise", "jsl", "mfs", "ncs", "dse", "nsl", "psr", "swl", "ase", "sfs", "cmn", "cmn-Hans", "cmn-Hant",
        "gan", "cmn", "hak", "", "nan", "wuu", "hsn"];
}

/** findOffset over the grandfathered list (case-insensitive), -1 when absent. */
function __mc_locale_grandfathered(string $tag): int
{
    $i = 0;
    foreach (__McLocaleTags::GRANDFATHERED as $g) {
        if (\strcasecmp($tag, $g) === 0) { return $i; }
        $i = $i + 1;
    }
    return -1;
}

function __mc_locale_is_sep(string $c): bool
{
    return $c === "_" || $c === "-";
}

/** getSingletonPos: position of a one-letter subtag (0 = at the start), -1 when none. */
function __mc_locale_singleton_pos(string $s): int
{
    $len = \strlen($s);
    $i = 0;
    while ($i < $len) {
        if (\__mc_locale_is_sep($s[$i])) {
            if ($i === 1) { return 0; }
            if ($i + 2 < $len && \__mc_locale_is_sep($s[$i + 2])) { return $i + 1; }
        }
        $i = $i + 1;
    }
    return -1;
}

/** getStrrtokenPos (the lookup truncation step). */
function __mc_locale_strrtoken_pos(string $s, int $saved): int
{
    $result = -1;
    $i = $saved - 1;
    while ($i >= 0) {
        if (\__mc_locale_is_sep($s[$i]) || $s[$i] === "@") {
            $result = $i >= 2 && \__mc_locale_is_sep($s[$i - 2]) ? $i - 2 : $i;
            break;
        }
        $i = $i - 1;
    }
    return $result < 1 ? -1 : $result;
}

/** get_icu_value_internal's answer — status: 1 found, -1 empty, 0 none — or, with a value, Zend's early return that leaves `result` unset. */
final class __McLocaleValue
{
    public function __construct(public int $status, public string $value) {}
}

/** get_icu_value_internal. */
function __mc_locale_value(string $loc, string $tag, bool $fromParse): __McLocaleValue
{
    if (\strlen($loc) > 156) { return new __McLocaleValue(0, ""); }
    $mod = $loc;
    if ($tag !== "canonicalize") {
        if (\__mc_locale_grandfathered($loc) >= 0) {
            return $tag === "language" ? new __McLocaleValue(0, $loc) : new __McLocaleValue(0, "");
        }
        if ($fromParse) {
            if ($tag === "language" && \strlen($loc) > 1 && \str_contains("xXiI", $loc[0]) && \__mc_locale_is_sep($loc[1])) {
                return new __McLocaleValue(0, $loc);
            }
            $sp = \__mc_locale_singleton_pos($loc);
            if ($sp === 0) { return new __McLocaleValue(0, ""); }
            if ($sp > 0) { $mod = \substr($loc, 0, $sp - 1); }
        }
    }
    $v = null;
    if ($tag === "script") {
        $v = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_uloc_getScript($mod, $b, $c, $e));
    } elseif ($tag === "language") {
        $v = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_uloc_getLanguage($mod, $b, $c, $e));
    } elseif ($tag === "region") {
        $v = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_uloc_getCountry($mod, $b, $c, $e));
    } elseif ($tag === "variant") {
        $v = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_uloc_getVariant($mod, $b, $c, $e));
    } else {
        $v = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_uloc_canonicalize($mod, $b, $c, $e));
    }
    if ($v === null) { return new __McLocaleValue(0, ""); }
    if ($v === "") { return new __McLocaleValue(-1, ""); }
    return new __McLocaleValue(1, $v);
}

/** INTL_CHECK_LOCALE_LEN: false (with the intl error set) when `$loc` is too long. */
function __mc_locale_len_ok(string $fn, string $loc): bool
{
    if (\strlen($loc) > 156) {
        \__mc_intl_fail($fn, "Locale string too long, should be no longer than 156 characters", 1);
        return false;
    }
    return true;
}

/** get_icu_value_src_php. */
function __mc_locale_get(string $fn, string $tag, string $locale): ?string
{
    \__mc_intl_reset();
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    if (!\__mc_locale_len_ok($fn, $locale)) { return null; }
    $r = \__mc_locale_value($locale, $tag, false);
    if ($r->status === -1) { return ""; }
    if ($r->value !== "") { return $r->value; }
    \__mc_intl_fail($fn, "unable to get locale " . $tag, 0);
    return null;
}

/** get_icu_disp_value_src_php. */
function __mc_locale_display(string $fn, string $tag, string $locale, ?string $displayLocale): string|false
{
    \__mc_intl_reset();
    if (\strlen($locale) > 157) {
        \__mc_intl_fail($fn, "name too long", 1);
        return false;
    }
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    $mod = $locale;
    if ($tag !== "name") {
        $g = \__mc_locale_grandfathered($locale);
        if ($g >= 0) {
            if ($tag !== "language") { return false; }
            $mod = __McLocaleTags::PREFERRED[$g] !== "" ? __McLocaleTags::PREFERRED[$g] : __McLocaleTags::GRANDFATHERED[$g];
        }
    }
    $disp = $displayLocale ?? \__mc_intl_default_locale();
    $cap = 512;
    while (true) {
        $buf = \__mc_icu_malloc($cap * 2);
        $e = \__mc_icu_err();
        $n = 0;
        if ($tag === "language") {
            $n = \__mc_icu_uloc_getDisplayLanguage($mod, $disp, $buf, $cap, $e);
        } elseif ($tag === "script") {
            $n = \__mc_icu_uloc_getDisplayScript($mod, $disp, $buf, $cap, $e);
        } elseif ($tag === "region") {
            $n = \__mc_icu_uloc_getDisplayCountry($mod, $disp, $buf, $cap, $e);
        } elseif ($tag === "variant") {
            $n = \__mc_icu_uloc_getDisplayVariant($mod, $disp, $buf, $cap, $e);
        } else {
            $n = \__mc_icu_uloc_getDisplayName($mod, $disp, $buf, $cap, $e);
        }
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code === 15) {
            \__mc_icu_free($buf);
            $cap = $n;
            continue;
        }
        if ($code > 0) {
            \__mc_icu_free($buf);
            \__mc_intl_fail($fn, "unable to get locale " . $tag, $code);
            return false;
        }
        $out = \__mc_icu_to8($buf, $n);
        \__mc_icu_free($buf);
        return $out;
    }
}

/** php_strtok_r over "-_": the non-empty tokens. @return string[] */
function __mc_locale_tokens(string $s): array
{
    $out = [];
    foreach (\preg_split('/[-_]+/', $s) as $t) {
        if ($t !== "") { $out[] = $t; }
    }
    return $out;
}

/** get_private_subtags. */
function __mc_locale_private(string $loc): ?string
{
    $mod = $loc;
    $len = \strlen($mod);
    if ($len === 0) { return null; }
    while (($sp = \__mc_locale_singleton_pos($mod)) > -1) {
        if ($mod[$sp] === "x" || $mod[$sp] === "X") {
            if ($sp + 2 === $len) { return null; }
            return \substr($mod, $sp + 2);
        }
        if ($sp + 1 >= $len) { return null; }
        $mod = \substr($mod, $sp + 1);
        $len = \strlen($mod);
    }
    return null;
}

/**
 * add_array_entry for locale_parse.
 * @param array<string,string> $out
 * @return array<string,string>
 */
function __mc_locale_parse_entry(string $loc, array $out, string $key): array
{
    if ($key === "private" || $key === "variant") {
        $value = null;
        if ($key === "private") {
            $value = \__mc_locale_private($loc);
        } else {
            $r = \__mc_locale_value($loc, $key, true);
            if ($r->status > 0) { $value = $r->value; }
        }
        if ($value !== null) {
            $toks = \__mc_locale_tokens($value);
            $i = 0;
            foreach ($toks as $t) {
                if ($i > 0 && \strlen($t) <= 1) { break; }
                $out[$key . $i] = $t;
                $i = $i + 1;
            }
        }
        return $out;
    }
    $r = \__mc_locale_value($loc, $key, true);
    if ($r->status === 1) { $out[$key] = $r->value; }
    return $out;
}

/** strToMatch: lower case, '-' → '_'. */
function __mc_locale_match_form(string $s): string
{
    return \strtolower(\str_replace("-", "_", $s));
}

class Locale
{
    public const ACTUAL_LOCALE = 0;
    public const VALID_LOCALE = 1;
    public const DEFAULT_LOCALE = null;
    public const LANG_TAG = "language";
    public const EXTLANG_TAG = "extlang";
    public const SCRIPT_TAG = "script";
    public const REGION_TAG = "region";
    public const VARIANT_TAG = "variant";
    public const GRANDFATHERED_LANG_TAG = "grandfathered";
    public const PRIVATE_TAG = "private";

    public static function getDefault(): string
    {
        return \__mc_intl_default_locale();
    }

    public static function setDefault(string $locale): bool
    {
        __McIntlLocale::$default = $locale === "" ? \cstr_to_str(\__mc_icu_uloc_getDefault()) : $locale;
        return true;
    }

    public static function getPrimaryLanguage(string $locale): ?string
    {
        return \__mc_locale_get("Locale::getPrimaryLanguage", "language", $locale);
    }

    public static function getScript(string $locale): ?string
    {
        return \__mc_locale_get("Locale::getScript", "script", $locale);
    }

    public static function getRegion(string $locale): ?string
    {
        return \__mc_locale_get("Locale::getRegion", "region", $locale);
    }

    /** @return array<string,string>|false|null */
    public static function getKeywords(string $locale): array|false|null
    {
        return \__mc_locale_keywords("Locale::getKeywords", $locale);
    }

    public static function getDisplayScript(string $locale, ?string $displayLocale = null): string|false
    {
        return \__mc_locale_display("Locale::getDisplayScript", "script", $locale, $displayLocale);
    }

    public static function getDisplayRegion(string $locale, ?string $displayLocale = null): string|false
    {
        return \__mc_locale_display("Locale::getDisplayRegion", "region", $locale, $displayLocale);
    }

    public static function getDisplayName(string $locale, ?string $displayLocale = null): string|false
    {
        return \__mc_locale_display("Locale::getDisplayName", "name", $locale, $displayLocale);
    }

    public static function getDisplayLanguage(string $locale, ?string $displayLocale = null): string|false
    {
        return \__mc_locale_display("Locale::getDisplayLanguage", "language", $locale, $displayLocale);
    }

    public static function getDisplayVariant(string $locale, ?string $displayLocale = null): string|false
    {
        return \__mc_locale_display("Locale::getDisplayVariant", "variant", $locale, $displayLocale);
    }

    /** @param array<mixed> $subtags */
    public static function composeLocale(array $subtags): string|false
    {
        return \__mc_locale_compose("Locale::composeLocale", $subtags);
    }

    /** @return array<string,string>|null */
    public static function parseLocale(string $locale): ?array
    {
        return \__mc_locale_parse("Locale::parseLocale", $locale);
    }

    /** @return string[]|null */
    public static function getAllVariants(string $locale): ?array
    {
        return \__mc_locale_variants("Locale::getAllVariants", $locale);
    }

    public static function filterMatches(string $languageTag, string $locale, bool $canonicalize = false): ?bool
    {
        return \__mc_locale_filter("Locale::filterMatches", $languageTag, $locale, $canonicalize);
    }

    /** @param array<mixed> $languageTag */
    public static function lookup(array $languageTag, string $locale, bool $canonicalize = false, ?string $defaultLocale = null): ?string
    {
        return \__mc_locale_lookup("Locale::lookup", $languageTag, $locale, $canonicalize, $defaultLocale);
    }

    public static function canonicalize(string $locale): ?string
    {
        return \__mc_locale_get("Locale::canonicalize", "canonicalize", $locale);
    }

    public static function acceptFromHttp(string $header): string|false
    {
        return \__mc_locale_accept("Locale::acceptFromHttp", $header);
    }

    public static function isRightToLeft(string $locale): bool
    {
        return \locale_is_right_to_left($locale);
    }

    public static function addLikelySubtags(string $locale): string|false
    {
        return \__mc_locale_subtags("Locale::addLikelySubtags", $locale, true);
    }

    public static function minimizeSubtags(string $locale): string|false
    {
        return \__mc_locale_subtags("Locale::minimizeSubtags", $locale, false);
    }
}

/** @return array<string,string>|false|null */
function __mc_locale_keywords(string $fn, string $locale): array|false|null
{
    \__mc_intl_reset();
    if (!\__mc_locale_len_ok($fn, $locale)) { return null; }
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    $e = \__mc_icu_err();
    $en = \__mc_icu_uloc_openKeywords($locale, $e);
    if (\ptr_to_int($en) === 0) {
        \__mc_icu_free($e);
        return null;
    }
    $out = [];
    $lenCell = \__mc_icu_malloc(8);
    while (true) {
        $k = \__mc_icu_uenum_next($en, $lenCell, $e);
        if (\ptr_to_int($k) === 0) { break; }
        $key = \cstr_to_str($k);
        $v = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $err): int => \__mc_icu_uloc_getKeywordValue($locale, $key, $b, $c, $err));
        if ($v === null) {
            \__mc_intl_fail($fn, "Error encountered while getting the keyword value for the keyword", 1);
            \__mc_icu_uenum_close($en);
            \__mc_icu_free($lenCell);
            \__mc_icu_free($e);
            return false;
        }
        $out[$key] = $v;
    }
    \__mc_icu_uenum_close($en);
    \__mc_icu_free($lenCell);
    \__mc_icu_free($e);
    return $out;
}

/** @param array<mixed> $subtags */
function __mc_locale_compose(string $fn, array $subtags): string|false
{
    \__mc_intl_reset();
    if ($subtags === []) { return false; }
    $bad = function () use ($fn): bool {
        \__mc_intl_reset();
        \__mc_intl_fail($fn, "parameter array element is not a string", 1);
        return false;
    };
    if (\array_key_exists("grandfathered", $subtags)) {
        if (!\is_string($subtags["grandfathered"])) { return $bad(); }
        return $subtags["grandfathered"];
    }
    if (!\array_key_exists("language", $subtags)) {
        throw new \ValueError($fn . "(): Argument #1 (\$subtags) must contain a \"language\" key");
    }
    if (!\is_string($subtags["language"])) { return $bad(); }
    $out = $subtags["language"];
    foreach (["extlang", "script", "region", "variant", "private"] as $key) {
        $multi = $key === "extlang" || $key === "variant" || $key === "private";
        if (!$multi) {
            if (\array_key_exists($key, $subtags)) {
                if (!\is_string($subtags[$key])) { return $bad(); }
                $out = $out . "_" . $subtags[$key];
            }
            continue;
        }
        $prefix = $key === "private" ? "_x" : "";
        if (\array_key_exists($key, $subtags)) {
            $v = $subtags[$key];
            if (\is_string($v)) {
                $out = $out . $prefix . "_" . $v;
            } elseif (\is_array($v)) {
                $first = true;
                foreach ($v as $item) {
                    if (!\is_string($item)) { return $bad(); }
                    if ($first) {
                        $out = $out . $prefix;
                        $first = false;
                    }
                    $out = $out . "_" . $item;
                }
            } else {
                return $bad();
            }
            continue;
        }
        $max = $key === "variant" ? 15 : ($key === "extlang" ? 3 : 15);
        $first = true;
        $i = 0;
        while ($i < $max) {
            $name = $key . $i;
            if (\array_key_exists($name, $subtags)) {
                if (!\is_string($subtags[$name])) { return $bad(); }
                if ($first) {
                    $out = $out . $prefix;
                    $first = false;
                }
                $out = $out . "_" . $subtags[$name];
            }
            $i = $i + 1;
        }
    }
    return $out;
}

/** @return array<string,string>|null */
function __mc_locale_parse(string $fn, string $locale): ?array
{
    \__mc_intl_reset();
    if (!\__mc_locale_len_ok($fn, $locale)) { return null; }
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    if (\__mc_locale_grandfathered($locale) >= 0) { return ["grandfathered" => $locale]; }
    $out = [];
    foreach (["language", "script", "region", "variant", "private"] as $key) {
        $out = \__mc_locale_parse_entry($locale, $out, $key);
    }
    return $out;
}

/** @return string[]|null */
function __mc_locale_variants(string $fn, string $locale): ?array
{
    \__mc_intl_reset();
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    if (!\__mc_locale_len_ok($fn, $locale)) { return null; }
    $out = [];
    if (\__mc_locale_grandfathered($locale) >= 0) { return $out; }
    $r = \__mc_locale_value($locale, "variant", false);
    if ($r->status > 0) {
        $i = 0;
        foreach (\__mc_locale_tokens($r->value) as $t) {
            if ($i > 0 && \strlen($t) <= 1) { break; }
            $out[] = $t;
            $i = $i + 1;
        }
    }
    return $out;
}

function __mc_locale_filter(string $fn, string $tag, string $range, bool $canonical): ?bool
{
    \__mc_intl_reset();
    if ($range === "") { $range = \__mc_intl_default_locale(); }
    if ($range === "*") { return true; }
    if (!\__mc_locale_len_ok($fn, $range) || !\__mc_locale_len_ok($fn, $tag)) { return null; }
    if ($canonical) {
        $r = \__mc_locale_value($range, "canonicalize", false);
        if ($r->status <= 0) {
            \__mc_intl_fail($fn, "unable to canonicalize loc_range", 0);
            return false;
        }
        $t = \__mc_locale_value($tag, "canonicalize", false);
        if ($t->status <= 0) {
            \__mc_intl_fail($fn, "unable to canonicalize lang_tag", 0);
            return false;
        }
        $range = $r->value;
        $tag = $t->value;
    }
    if ($tag === "" || $range === "") { return false; }
    $lt = \__mc_locale_match_form($tag);
    $lr = \__mc_locale_match_form($range);
    if (!\str_starts_with($lt, $lr)) { return false; }
    $next = \strlen($lr) < \strlen($lt) ? $lt[\strlen($lr)] : "";
    return $next === "" || \__mc_locale_is_sep($next) || ($canonical && $next === "@");
}

/** @param array<mixed> $tags */
function __mc_locale_lookup(string $fn, array $tags, string $range, bool $canonical, ?string $fallback): ?string
{
    \__mc_intl_reset();
    if ($range === "") { $range = $fallback ?? \__mc_intl_default_locale(); }
    if (!\__mc_locale_len_ok($fn, $range)) { return null; }
    if ($tags === []) { return ""; }
    $forms = [];
    $orig = [];
    foreach ($tags as $v) {
        if (!\is_string($v)) {
            throw new \TypeError($fn . "(): Argument #2 (\$languageTag) must only contain string values");
        }
        if (\str_contains($v, "\x00")) {
            throw new \ValueError($fn . "(): Argument #2 (\$languageTag) must not contain any null bytes");
        }
        if ($v === "") {
            \__mc_intl_fail($fn, "unable to canonicalize lang_tag", 1);
            return null;
        }
        $forms[] = \__mc_locale_match_form($v);
        $orig[] = $v;
    }
    if ($canonical) {
        $i = 0;
        while ($i < \count($orig)) {
            $c = \__mc_locale_value($forms[$i], "canonicalize", false);
            if ($c->status !== 1 || $c->value === "") {
                \__mc_intl_fail($fn, "unable to canonicalize lang_tag", 1);
                return null;
            }
            $forms[$i] = \__mc_locale_match_form($c->value);
            $i = $i + 1;
        }
        $c = \__mc_locale_value($range, "canonicalize", false);
        if ($c->status !== 1 || $c->value === "") {
            \__mc_intl_fail($fn, "unable to canonicalize loc_range", 1);
            return null;
        }
        $range = $c->value;
    }
    if ($range === "") {
        \__mc_intl_fail($fn, "unable to canonicalize lang_tag", 1);
        return null;
    }
    $cur = \__mc_locale_match_form($range);
    $saved = \strlen($cur);
    while ($saved > 0) {
        $i = 0;
        while ($i < \count($forms)) {
            if (\strlen($forms[$i]) === $saved && \strncmp($cur, $forms[$i], $saved) === 0) {
                $hit = $canonical ? $forms[$i] : $orig[$i];
                return $hit !== "" ? $hit : ($fallback ?? "");
            }
            $i = $i + 1;
        }
        $saved = \__mc_locale_strrtoken_pos($cur, $saved);
    }
    return $fallback ?? "";
}

function __mc_locale_accept(string $fn, string $header): string|false
{
    if (\strlen($header) > 157) {
        foreach (\explode(",", $header) as $part) {
            if (\strlen($part) > 157) {
                \__mc_intl_fail($fn, "locale string too long", 1);
                return false;
            }
        }
    }
    $e = \__mc_icu_err();
    $available = \__mc_icu_ures_openAvailableLocales(\int_to_ptr(0), $e);
    if (\peek_i32($e, 0) > 0) {
        \__mc_intl_fail($fn, "failed to retrieve locale list", \peek_i32($e, 0));
        \__mc_icu_free($e);
        return false;
    }
    $buf = \__mc_icu_malloc(160);
    $res = \__mc_icu_malloc(8);
    \poke_i32($res, 0, 0);
    $n = \__mc_icu_uloc_acceptLanguageFromHTTP($buf, 156, $res, $header, $available, $e);
    \__mc_icu_uenum_close($available);
    $code = \peek_i32($e, 0);
    $outcome = \peek_i32($res, 0);
    \__mc_icu_free($e);
    \__mc_icu_free($res);
    if ($code > 0) {
        \__mc_icu_free($buf);
        \__mc_intl_fail($fn, "failed to find acceptable locale", $code);
        return false;
    }
    if ($n < 0 || $outcome === 0) {
        \__mc_icu_free($buf);
        return false;
    }
    $out = \str_from_buffer($buf, $n);
    \__mc_icu_free($buf);
    return $out;
}

function __mc_locale_subtags(string $fn, string $locale, bool $add): string|false
{
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    $buf = \__mc_icu_malloc(158);
    $e = \__mc_icu_err();
    $n = $add ? \__mc_icu_uloc_addLikelySubtags($locale, $buf, 157, $e) : \__mc_icu_uloc_minimizeSubtags($locale, $buf, 157, $e);
    $code = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($code > 0) {
        \__mc_icu_free($buf);
        \__mc_intl_fail($fn, "invalid locale", $code);
        return false;
    }
    $out = $n < 0 ? false : \str_from_buffer($buf, $n);
    \__mc_icu_free($buf);
    return $out;
}

function locale_get_default(): string
{
    return Locale::getDefault();
}

function locale_set_default(string $locale): bool
{
    return Locale::setDefault($locale);
}

function locale_get_primary_language(string $locale): ?string
{
    return \__mc_locale_get("locale_get_primary_language", "language", $locale);
}

function locale_get_script(string $locale): ?string
{
    return \__mc_locale_get("locale_get_script", "script", $locale);
}

function locale_get_region(string $locale): ?string
{
    return \__mc_locale_get("locale_get_region", "region", $locale);
}

/** @return array<string,string>|false|null */
function locale_get_keywords(string $locale): array|false|null
{
    return \__mc_locale_keywords("locale_get_keywords", $locale);
}

function locale_get_display_script(string $locale, ?string $displayLocale = null): string|false
{
    return \__mc_locale_display("locale_get_display_script", "script", $locale, $displayLocale);
}

function locale_get_display_region(string $locale, ?string $displayLocale = null): string|false
{
    return \__mc_locale_display("locale_get_display_region", "region", $locale, $displayLocale);
}

function locale_get_display_name(string $locale, ?string $displayLocale = null): string|false
{
    return \__mc_locale_display("locale_get_display_name", "name", $locale, $displayLocale);
}

function locale_get_display_language(string $locale, ?string $displayLocale = null): string|false
{
    return \__mc_locale_display("locale_get_display_language", "language", $locale, $displayLocale);
}

function locale_get_display_variant(string $locale, ?string $displayLocale = null): string|false
{
    return \__mc_locale_display("locale_get_display_variant", "variant", $locale, $displayLocale);
}

/** @param array<mixed> $subtags */
function locale_compose(array $subtags): string|false
{
    return \__mc_locale_compose("locale_compose", $subtags);
}

/** @return array<string,string>|null */
function locale_parse(string $locale): ?array
{
    return \__mc_locale_parse("locale_parse", $locale);
}

/** @return string[]|null */
function locale_get_all_variants(string $locale): ?array
{
    return \__mc_locale_variants("locale_get_all_variants", $locale);
}

function locale_filter_matches(string $languageTag, string $locale, bool $canonicalize = false): ?bool
{
    return \__mc_locale_filter("locale_filter_matches", $languageTag, $locale, $canonicalize);
}

function locale_canonicalize(string $locale): ?string
{
    return \__mc_locale_get("locale_canonicalize", "canonicalize", $locale);
}

/** @param array<mixed> $languageTag */
function locale_lookup(array $languageTag, string $locale, bool $canonicalize = false, ?string $defaultLocale = null): ?string
{
    return \__mc_locale_lookup("locale_lookup", $languageTag, $locale, $canonicalize, $defaultLocale);
}

function locale_accept_from_http(string $header): string|false
{
    return \__mc_locale_accept("locale_accept_from_http", $header);
}

function locale_is_right_to_left(string $locale): bool
{
    if ($locale === "") { $locale = \__mc_intl_default_locale(); }
    return (\__mc_icu_uloc_isRightToLeft($locale) & 0xFF) !== 0;
}

function locale_add_likely_subtags(string $locale): string|false
{
    return \__mc_locale_subtags("locale_add_likely_subtags", $locale, true);
}

function locale_minimize_subtags(string $locale): string|false
{
    return \__mc_locale_subtags("locale_minimize_subtags", $locale, false);
}
