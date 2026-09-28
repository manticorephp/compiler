<?php

// ext/intl ResourceBundle (php-src ext/intl/resourcebundle/*) over ICU's ures_* C API. The
// element read (`$rb['key']`) rides ArrayAccess — Zend uses an object handler, so here
// `$rb instanceof ArrayAccess` is true where php answers false, and isset() answers a bool
// where php throws; a write or unset throws php's "Cannot use object of type ResourceBundle as array".

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_open')]
function __mc_icu_ures_open(\Ffi\Ptr $package, string $locale, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_openDirect')]
function __mc_icu_ures_openDirect_pkg(\Ffi\Ptr $package, string $locale, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getLocaleByType')]
function __mc_icu_ures_getLocaleByType(\Ffi\Ptr $res, #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_openAvailableLocales')]
function __mc_icu_ures_openAvailableLocales(\Ffi\Ptr $package, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getByKey')]
function __mc_icu_rb_getByKey(\Ffi\Ptr $res, string $key, \Ffi\Ptr $fill, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getByIndex')]
function __mc_icu_rb_getByIndex(\Ffi\Ptr $res, #[\Ffi\CType('int')] int $i, \Ffi\Ptr $fill, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getType'), \Ffi\CType('int')]
function __mc_icu_rb_getType(\Ffi\Ptr $res): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getSize'), \Ffi\CType('int')]
function __mc_icu_rb_getSize(\Ffi\Ptr $res): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getKey')]
function __mc_icu_rb_getKey(\Ffi\Ptr $res): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getString')]
function __mc_icu_rb_getString(\Ffi\Ptr $res, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getBinary')]
function __mc_icu_rb_getBinary(\Ffi\Ptr $res, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getInt'), \Ffi\CType('int')]
function __mc_icu_rb_getInt(\Ffi\Ptr $res, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getIntVector')]
function __mc_icu_rb_getIntVector(\Ffi\Ptr $res, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_close')]
function __mc_icu_rb_close(\Ffi\Ptr $res): void {}

class ResourceBundle implements IteratorAggregate, Countable, ArrayAccess
{
    public static bool $__mcInert = false;

    private int $__mcMe = 0;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    public function __construct(?string $locale, ?string $bundle, bool $fallback = true)
    {
        if (self::$__mcInert) { return; }
        if ($this->__mcMe !== 0) {
            throw new \Error("ResourceBundle object is already constructed");
        }
        $this->__mcInit("ResourceBundle::__construct", true, $locale, $bundle, $fallback);
    }

    public static function __mcAdopt(int $res): ResourceBundle
    {
        self::$__mcInert = true;
        $rb = new ResourceBundle(null, null);
        self::$__mcInert = false;
        $rb->__mcMe = $res;
        return $rb;
    }

    public function __destruct()
    {
        if ($this->__mcMe !== 0) { \__mc_icu_rb_close(\int_to_ptr($this->__mcMe)); $this->__mcMe = 0; }
    }

    public function __clone()
    {
        throw new \Error("Trying to clone an uncloneable object of class ResourceBundle");
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __mcInit(string $fn, bool $throw, ?string $locale, ?string $bundle, bool $fallback): bool
    {
        \__mc_intl_reset();
        $loc = $locale ?? "";
        if (\strlen($loc) > 156) {
            \__mc_intl_fail($fn, "Locale string too long, should be no longer than 156 characters", 1);
            if ($throw) { throw new IntlException($fn . "(): Locale string too long, should be no longer than 156 characters"); }
            return false;
        }
        if ($locale === null) { $loc = \__mc_intl_default_locale(); }
        if ($bundle !== null && \strlen($bundle) >= 1024) {
            throw new \ValueError($fn . "(): Argument #2 (\$bundle) is too long");
        }
        $pkg = $bundle === null ? \int_to_ptr(0) : \__mc_icu_cstr($bundle);
        $e = \__mc_icu_err();
        $me = $fallback ? \__mc_icu_ures_open($pkg, $loc, $e) : \__mc_icu_ures_openDirect_pkg($pkg, $loc, $e);
        $c = \peek_i32($e, 0);
        if ($bundle !== null) { \__mc_icu_free($pkg); }
        $this->__mcErrCode = $c;
        if ($c > 0) {
            \__mc_icu_free($e);
            $this->__mcErrMessage = \__mc_intl_message($fn, "Cannot load libICU resource bundle", $c);
            \__mc_intl_fail($fn, "Cannot load libICU resource bundle", $c);
            if ($throw) { throw new IntlException($fn . "(): Cannot load libICU resource bundle"); }
            return false;
        }
        $this->__mcMe = \ptr_to_int($me);
        if (!$fallback && ($c === -128 || $c === -127)) {
            \poke_i32($e, 0, 0);
            $actual = \cstr_to_str(\__mc_icu_ures_getLocaleByType($me, 0, $e));
            $what = "Cannot load libICU resource '" . ($bundle ?? "(default data)") . "' without fallback from " . $loc . " to " . $actual;
            \__mc_icu_free($e);
            __McIntlError::$code = $c;
            $this->__mcErrMessage = \__mc_intl_message($fn, $what, $c);
            if ($throw) { throw new IntlException($fn . "(): " . $what); }
            return false;
        }
        \__mc_icu_free($e);
        return true;
    }

    public static function create(?string $locale, ?string $bundle, bool $fallback = true): ?ResourceBundle
    {
        return \__mc_rb_create("ResourceBundle::create", $locale, $bundle, $fallback);
    }

    /** resource_bundle_array_fetch: `$argNum` 0 is the `[]` read. */
    public function __mcFetch(string $fn, int|string $index, bool $fallback, int $argNum): mixed
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
        $e = \__mc_icu_err();
        $me = \int_to_ptr($this->__mcMe);
        if (\is_string($index)) {
            if ($index === "") {
                \__mc_icu_free($e);
                if ($argNum > 0) { throw new \ValueError($fn . "(): Argument #" . (string)$argNum . " (\$index) must not be empty"); }
                throw new \ValueError("Offset must not be empty");
            }
            $child = \__mc_icu_rb_getByKey($me, $index, \int_to_ptr(0), $e);
        } else {
            if ($index < -2147483648 || $index > 2147483647) {
                \__mc_icu_free($e);
                if ($argNum > 0) { throw new \ValueError($fn . "(): Argument #" . (string)$argNum . " (\$index) index must be between -2147483648 and 2147483647"); }
                throw new \ValueError("Index must be between -2147483648 and 2147483647");
            }
            $child = \__mc_icu_rb_getByIndex($me, $index, \int_to_ptr(0), $e);
        }
        $c = \peek_i32($e, 0);
        __McIntlError::$code = $c > 0 ? $c : 0;
        $this->__mcErrCode = $c;
        $label = \is_string($index) ? "'" . $index . "'" : (string)$index;
        if ($c > 0) {
            \__mc_icu_free($e);
            if (\ptr_to_int($child) !== 0) { \__mc_icu_rb_close($child); }
            $this->__mcErrMessage = \__mc_intl_message($fn, "Cannot load resource element " . $label, $c);
            return null;
        }
        if (!$fallback && ($c === -128 || $c === -127)) {
            \poke_i32($e, 0, 0);
            $loc = \cstr_to_str(\__mc_icu_ures_getLocaleByType($me, 0, $e));
            \__mc_icu_free($e);
            \__mc_icu_rb_close($child);
            $this->__mcErrMessage = \__mc_intl_message($fn, "Cannot load element " . $label . " without fallback from to " . $loc, $c);
            return null;
        }
        \__mc_icu_free($e);
        return $this->__mcExtract($fn, $child);
    }

    /** resourcebundle_extract_value: a php value for `$child`, which it takes ownership of. */
    public function __mcExtract(string $fn, \Ffi\Ptr $child): mixed
    {
        $t = \__mc_icu_rb_getType($child);
        if ($t === 2 || $t === 4 || $t === 5 || $t === 8 || $t === 9) {
            return ResourceBundle::__mcAdopt(\ptr_to_int($child));
        }
        $e = \__mc_icu_err();
        $len = \__mc_icu_malloc(8);
        \poke_i32($len, 0, 0);
        $out = null;
        if ($t === 0 || $t === 6) {
            $p = \__mc_icu_rb_getString($child, $len, $e);
            $out = \__mc_icu_to8($p, \peek_i32($len, 0));
        } elseif ($t === 1) {
            $p = \__mc_icu_rb_getBinary($child, $len, $e);
            $out = \str_from_buffer($p, \peek_i32($len, 0));
        } elseif ($t === 7) {
            $out = \__mc_icu_rb_getInt($child, $e);
        } elseif ($t === 14) {
            $p = \__mc_icu_rb_getIntVector($child, $len, $e);
            $n = \peek_i32($len, 0);
            $out = [];
            $i = 0;
            while ($i < $n) { $out[] = \peek_i32($p, 4 * $i); $i = $i + 1; }
        }
        \__mc_icu_free($len);
        \__mc_icu_free($e);
        \__mc_icu_rb_close($child);
        return $out;
    }

    public function get(string|int $index, bool $fallback = true): ResourceBundle|array|string|int|null
    {
        return $this->__mcFetch("ResourceBundle::get", $index, $fallback, 1);
    }

    public function count(): int
    {
        \__mc_intl_reset();
        return \__mc_icu_rb_getSize(\int_to_ptr($this->__mcMe));
    }

    public static function getLocales(string $bundle): array|false
    {
        return \__mc_rb_locales("ResourceBundle::getLocales", $bundle);
    }

    public function getErrorCode(): int
    {
        \__mc_intl_reset();
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): string
    {
        \__mc_intl_reset();
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }

    public function getIterator(): Iterator
    {
        return InternalIterator::__mcWrap(new __McRbCursor($this, \__mc_icu_rb_getType(\int_to_ptr($this->__mcMe)) === 2,
            \__mc_icu_rb_getSize(\int_to_ptr($this->__mcMe))));
    }

    /** One element by index for the iterator: [key, value], the key null when the read failed. */
    public function __mcAt(int $i, bool $table): array
    {
        $e = \__mc_icu_err();
        $child = \__mc_icu_rb_getByIndex(\int_to_ptr($this->__mcMe), $i, \int_to_ptr(0), $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) { return [null, null]; }
        $key = $table ? \cstr_to_str(\__mc_icu_rb_getKey($child)) : $i;
        return [$key, $this->__mcExtract("ResourceBundle::getIterator", $child)];
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!\is_int($offset) && !\is_string($offset)) {
            throw new \TypeError("Cannot access offset of type " . \get_debug_type($offset) . " on ResourceBundle");
        }
        return $this->__mcFetch("ResourceBundle::get", $offset, true, 0);
    }

    /**
     * `$rb[$k] ?? $d` reads through here first; php's handler reads the element
     * (BP_VAR_IS), so this answers whether one is there. A bare isset() therefore
     * answers a bool where php throws "Cannot use object of type ResourceBundle as array".
     */
    public function offsetExists(mixed $offset): bool
    {
        if (!\is_int($offset) && !\is_string($offset)) { return false; }
        if ($offset === "") { return false; }
        return $this->__mcFetch("ResourceBundle::get", $offset, true, 0) !== null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \Error("Cannot use object of type ResourceBundle as array");
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \Error("Cannot use object of type ResourceBundle as array");
    }
}

/** resourcebundle_iterator_*: index order, a table's keys read back from each element. */
final class __McRbCursor implements Iterator
{
    private int $__mcI = 0;
    private bool $__mcRead = false;
    private mixed $__mcKey = null;
    private mixed $__mcVal = null;

    public function __construct(private ResourceBundle $__mcRb, private bool $__mcTable, private int $__mcLen) {}

    private function __mcLoad(): void
    {
        if ($this->__mcRead) { return; }
        $this->__mcRead = true;
        $kv = $this->__mcRb->__mcAt($this->__mcI, $this->__mcTable);
        $this->__mcKey = $kv[0];
        $this->__mcVal = $kv[1];
    }

    public function current(): mixed
    {
        $this->__mcLoad();
        return $this->__mcVal;
    }

    public function key(): mixed
    {
        $this->__mcLoad();
        return $this->__mcTable ? $this->__mcKey : $this->__mcI;
    }

    public function next(): void
    {
        $this->__mcI = $this->__mcI + 1;
        $this->__mcRead = false;
        $this->__mcVal = null;
    }

    public function rewind(): void
    {
        $this->__mcI = 0;
        $this->__mcRead = false;
        $this->__mcVal = null;
    }

    public function valid(): bool
    {
        return $this->__mcI < $this->__mcLen;
    }
}

function __mc_rb_create(string $fn, ?string $locale, ?string $bundle, bool $fallback): ?ResourceBundle
{
    ResourceBundle::$__mcInert = true;
    $rb = new ResourceBundle(null, null);
    ResourceBundle::$__mcInert = false;
    return $rb->__mcInit($fn, false, $locale, $bundle, $fallback) ? $rb : null;
}

/** @return string[]|false */
function __mc_rb_locales(string $fn, string $bundle): array|false
{
    \__mc_intl_reset();
    if (\strlen($bundle) >= 1024) {
        throw new \ValueError($fn . "(): Argument #1 (\$bundle) is too long");
    }
    $pkg = $bundle === "" ? \int_to_ptr(0) : \__mc_icu_cstr($bundle);
    $e = \__mc_icu_err();
    $en = \__mc_icu_ures_openAvailableLocales($pkg, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($bundle !== "") { \__mc_icu_free($pkg); }
    if ($c > 0) {
        \__mc_intl_fail($fn, "Cannot fetch locales list", $c);
        return false;
    }
    return \__mc_icu_enum_strings($en);
}

function resourcebundle_create(?string $locale, ?string $bundle, bool $fallback = true): ?ResourceBundle
{
    return \__mc_rb_create("resourcebundle_create", $locale, $bundle, $fallback);
}

function resourcebundle_get(ResourceBundle $bundle, string|int $index, bool $fallback = true): mixed
{
    return $bundle->__mcFetch("resourcebundle_get", $index, $fallback, 2);
}

function resourcebundle_count(ResourceBundle $bundle): int
{
    return $bundle->count();
}

/** @return string[]|false */
function resourcebundle_locales(string $bundle): array|false
{
    return \__mc_rb_locales("resourcebundle_locales", $bundle);
}

function resourcebundle_get_error_code(ResourceBundle $bundle): int
{
    return $bundle->getErrorCode();
}

function resourcebundle_get_error_message(ResourceBundle $bundle): string
{
    return $bundle->getErrorMessage();
}
