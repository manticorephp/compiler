<?php

namespace Compile\TypeHint;

/**
 * Pure parser for PHP type hint syntax with generics, nullable
 * markers, and union sugar. Lives outside ClassResolution so the
 * compiler has one canonical entry point for every docblock /
 * source-level hint it has to interpret.
 *
 * Supported shapes (all parse into a TypeNode tree):
 *
 *     int                       — scalar
 *     Foo                       — class
 *     \Foo\Bar                  — namespaced class (leading `\`)
 *     ?Foo                      — nullable (sugar for `Foo|null`)
 *     Foo|null                  — same; trailing-null union
 *     null|Foo                  — same; leading-null union
 *     Foo[]                     — array of Foo (sugar for `array<Foo>`)
 *     array<Foo>                — vec
 *     array<int, Foo>           — vec with key type
 *     array<string, Foo>        — assoc with string key
 *     array<string, Foo|null>   — nested nullable
 *
 * The parser does NOT use `ltrim` / `rtrim` on the input string —
 * the self-host runtime's trim helpers occasionally return a buffer
 * whose subsequent `substr` reads back garbage at non-trivial
 * offsets (see [[selfhost-string-neq-after-ltrim]]). All scanning
 * goes through `ord(\$raw[\$i])` against a single immutable input.
 *
 * Until full PHP generics arrive in the codebase, this module
 * carries the syntax purely from docblocks. When generics land
 * in the real type system, the parser frontend stays — only the
 * call sites move from `extractDocVarHint` to source-level type
 * AST nodes.
 */
final class GenericType
{
    /**
     * @param GenericType[] $params  Generic / element parameters.
     */
    public function __construct(
        /** Base name, e.g. `Foo`, `\\Foo\\Bar`, `array`, `int`. */
        public readonly string $name,
        public readonly array $params,
        /** `?T` / `T|null` / `null|T` collapses to nullable=true. */
        public readonly bool $nullable,
        /** `T[]` sugar — pretends to be `array<T>` but tracked
         *  separately so callers can distinguish source shape. */
        public readonly bool $isArraySugar,
    ) {}

    /**
     * Parse a hint string. Returns null on malformed input. NULL
     * input or empty string also yield null — caller is expected
     * to treat that as "no hint, use defaults."
     */
    public static function parse(?string $raw): ?self
    {
        if ($raw === null) { return null; }
        $len = \strlen($raw);
        if ($len === 0) { return null; }
        $cursor = new GenericCursor($raw, 0, $len);
        $node = self::parseNode($cursor);
        if ($node === null) { return null; }
        // Allow trailing whitespace.
        self::skipWhitespace($cursor);
        if ($cursor->pos < $cursor->len) {
            // Unexpected trailing chars — accept anyway to stay
            // permissive against docblock authors.
        }
        return $node;
    }

    /**
     * `array<...>` or `T[]` shape detection.
     */
    public function isArrayLike(): bool
    {
        if ($this->isArraySugar) { return true; }
        return \strtolower($this->name) === 'array';
    }

    /**
     * `array<string, V>` shape — first param is `string`.
     */
    public function isAssoc(): bool
    {
        if (!$this->isArrayLike()) { return false; }
        if ($this->isArraySugar) { return false; }
        $count = \count($this->params);
        if ($count < 2) { return false; }
        $key = $this->params[0];
        return \strtolower($key->name) === 'string';
    }

    /**
     * Element / value type for an array-like hint:
     *  - `T[]`                 → T
     *  - `array<T>`            → T
     *  - `array<K, V>`         → V
     *  - non-array             → null
     */
    public function elementHint(): ?self
    {
        if ($this->isArraySugar) {
            // Sugar carries the element as the single param.
            $count = \count($this->params);
            if ($count === 0) { return null; }
            return $this->params[0];
        }
        if (\strtolower($this->name) !== 'array') { return null; }
        $count = \count($this->params);
        if ($count === 0) { return null; }
        return $this->params[$count - 1];
    }

    /**
     * Raw element-type name (no `<...>` params) — what the legacy
     * `arrayElementHintRaw` used to hand back. Drops nullability
     * markers so a `Foo|null` element-name reads as `Foo`.
     */
    public function elementName(): ?string
    {
        $elem = $this->elementHint();
        if ($elem === null) { return null; }
        return $elem->name === '' ? null : $elem->name;
    }

    /**
     * Round-trip back to a canonical hint string. Used by the
     * compiler when it needs to forward a (possibly normalised)
     * hint into a downstream call that still takes raw text.
     */
    public function toString(): string
    {
        $out = $this->name;
        $pCount = \count($this->params);
        if ($pCount > 0 && !$this->isArraySugar) {
            $out .= '<';
            for ($i = 0; $i < $pCount; $i = $i + 1) {
                if ($i > 0) { $out .= ','; }
                $out .= $this->params[$i]->toString();
            }
            $out .= '>';
        }
        if ($this->isArraySugar) {
            $out .= '[]';
        }
        if ($this->nullable) {
            $out = '?' . $out;
        }
        return $out;
    }

    // ── parsing ──────────────────────────────────────────────────

    private static function parseNode(GenericCursor $c): ?self
    {
        self::skipWhitespace($c);
        if ($c->pos >= $c->len) { return null; }
        $nullable = false;
        // Leading `?` marker.
        if (\ord($c->raw[$c->pos]) === 63) { // '?'
            $nullable = true;
            $c->pos = $c->pos + 1;
            self::skipWhitespace($c);
        }
        // Optional `null|` prefix.
        if (self::matchKeyword($c, 'null')) {
            $savePos = $c->pos;
            self::skipWhitespace($c);
            if ($c->pos < $c->len && \ord($c->raw[$c->pos]) === 124) {
                // `null|...` — element is what follows.
                $c->pos = $c->pos + 1;
                $rest = self::parseNode($c);
                if ($rest === null) { return null; }
                return new self(
                    name: $rest->name,
                    params: $rest->params,
                    nullable: true,
                    isArraySugar: $rest->isArraySugar,
                );
            }
            // Bare `null` keyword → standalone null type.
            $c->pos = $savePos;
            return new self('null', [], true, false);
        }
        $name = self::parseIdentifier($c);
        if ($name === '') { return null; }
        $params = [];
        self::skipWhitespace($c);
        if ($c->pos < $c->len && \ord($c->raw[$c->pos]) === 60) { // '<'
            $c->pos = $c->pos + 1;
            $params = self::parseParamList($c);
            self::skipWhitespace($c);
            if ($c->pos < $c->len && \ord($c->raw[$c->pos]) === 62) { // '>'
                $c->pos = $c->pos + 1;
            }
        }
        $isArraySugar = false;
        self::skipWhitespace($c);
        if ($c->pos + 1 < $c->len
            && \ord($c->raw[$c->pos]) === 91     // '['
            && \ord($c->raw[$c->pos + 1]) === 93 // ']'
        ) {
            $c->pos = $c->pos + 2;
            $isArraySugar = true;
            // Hoist the parsed base into the sugar's single param.
            $params = [new self($name, $params, false, false)];
            $name = 'array';
        }
        // Trailing `|null` union sugar.
        self::skipWhitespace($c);
        if ($c->pos < $c->len && \ord($c->raw[$c->pos]) === 124) { // '|'
            $savePos = $c->pos;
            $c->pos = $c->pos + 1;
            self::skipWhitespace($c);
            if (self::matchKeyword($c, 'null')) {
                $nullable = true;
            } else {
                // Some other union member — give up on parsing the
                // rest; callers that care about full unions can
                // ladder up the type table themselves.
                $c->pos = $savePos;
            }
        }
        return new self($name, $params, $nullable, $isArraySugar);
    }

    /**
     * @return self[]
     */
    private static function parseParamList(GenericCursor $c): array
    {
        $out = [];
        self::skipWhitespace($c);
        if ($c->pos < $c->len && \ord($c->raw[$c->pos]) === 62) {
            // Empty `<>` — bail.
            return $out;
        }
        while ($c->pos < $c->len) {
            $node = self::parseNode($c);
            if ($node === null) { break; }
            $out[] = $node;
            self::skipWhitespace($c);
            if ($c->pos >= $c->len) { break; }
            $ch = \ord($c->raw[$c->pos]);
            if ($ch === 44) { // ','
                $c->pos = $c->pos + 1;
                continue;
            }
            break;
        }
        return $out;
    }

    private static function parseIdentifier(GenericCursor $c): string
    {
        $start = $c->pos;
        while ($c->pos < $c->len) {
            $ch = \ord($c->raw[$c->pos]);
            // alpha / digit / underscore / namespace separator
            $isAlpha = ($ch >= 65 && $ch <= 90) || ($ch >= 97 && $ch <= 122);
            $isDigit = $ch >= 48 && $ch <= 57;
            $isUnd = $ch === 95;
            $isBs = $ch === 92; // '\\'
            if (!$isAlpha && !$isDigit && !$isUnd && !$isBs) { break; }
            $c->pos = $c->pos + 1;
        }
        if ($c->pos === $start) { return ''; }
        return \substr($c->raw, $start, $c->pos - $start);
    }

    private static function matchKeyword(GenericCursor $c, string $kw): bool
    {
        $kwLen = \strlen($kw);
        if ($c->pos + $kwLen > $c->len) { return false; }
        for ($i = 0; $i < $kwLen; $i = $i + 1) {
            $a = \ord($c->raw[$c->pos + $i]);
            $b = \ord($kw[$i]);
            // Case-insensitive: fold A-Z into a-z.
            if ($a >= 65 && $a <= 90) { $a = $a + 32; }
            if ($b >= 65 && $b <= 90) { $b = $b + 32; }
            if ($a !== $b) { return false; }
        }
        // Must not be followed by an identifier char.
        if ($c->pos + $kwLen < $c->len) {
            $next = \ord($c->raw[$c->pos + $kwLen]);
            $isCont = ($next >= 65 && $next <= 90)
                || ($next >= 97 && $next <= 122)
                || ($next >= 48 && $next <= 57)
                || $next === 95 || $next === 92;
            if ($isCont) { return false; }
        }
        $c->pos = $c->pos + $kwLen;
        return true;
    }

    private static function skipWhitespace(GenericCursor $c): void
    {
        while ($c->pos < $c->len) {
            $ch = \ord($c->raw[$c->pos]);
            if ($ch !== 32 && $ch !== 9 && $ch !== 10 && $ch !== 13) {
                break;
            }
            $c->pos = $c->pos + 1;
        }
    }
}
