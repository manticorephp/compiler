<?php

/**
 * ext/tokenizer, public surface: PhpToken, token_get_all(), token_name().
 *
 * Split from the scanner core (prelude/tokenizer.php) because these names are
 * Zend's — this file cannot be require'd under `php` without a redeclare fatal,
 * and the core must stay loadable there for the differential harness. Do not
 * merge the two.
 *
 * ⚠ THE ONE CELL ARRAY LIVES HERE. token_get_all() is specified to return
 * `array<int, array{int,string,int}|string>` — the heterogeneous shape this
 * codebase handles worst. It is built exactly once, at this boundary, never per
 * token inside the scanner. The `@var array<int,mixed>` pin below is
 * load-bearing: without it the first arm types $out as a packed string array and
 * the second arm widens it, which is the fragile path.
 */

/** @return array<int,mixed> */
function token_get_all(string $code, int $flags = 0): array
{
    $t = __McTok::run($code, $flags);
    /** @var array<int,mixed> $out */
    $out = [];
    $meta = $t->meta;
    $texts = $t->texts;
    $n = $t->n;
    $i = 0;
    while ($i < $n) {
        $m = $meta[$i];
        $id = $m & 4095;
        // A single-character token is a BARE STRING in the legacy shape. Its id
        // IS its byte, which is what makes this test both correct and free.
        if ($id < 256) {
            $out[] = $texts[$i];
        } else {
            $out[] = [$id, $texts[$i], $m >> 12];
        }
        $i = $i + 1;
    }
    return $out;
}

/** Zend answers 'UNKNOWN' for anything that is not a T_* id — single-character
 *  ids included. PhpToken::getTokenName() does NOT: see below. */
function token_name(int $id): string
{
    $names = __mc_tok_names();
    return $names[$id] ?? 'UNKNOWN';
}

class PhpToken implements Stringable
{
    public int $id = 0;
    public string $text = '';
    public int $line = -1;
    public int $pos = -1;

    public function __construct(int $id, string $text, int $line = -1, int $pos = -1)
    {
        $this->id = $id;
        $this->text = $text;
        $this->line = $line;
        $this->pos = $pos;
    }

    /** @return PhpToken[] */
    public static function tokenize(string $code, int $flags = 0): array
    {
        $t = __McTok::run($code, $flags, 1);
        /** @var PhpToken[] $out */
        $out = [];
        $meta = $t->meta;
        $texts = $t->texts;
        $offs = $t->offs;
        $n = $t->n;
        $i = 0;
        while ($i < $n) {
            $m = $meta[$i];
            $out[] = new PhpToken($m & 4095, $texts[$i], $m >> 12, $offs[$i]);
            $i = $i + 1;
        }
        return $out;
    }

    /**
     * A STRING argument matches the token's TEXT, not its name: `is('T_STRING')`
     * is false, `is('foo')` is true for the identifier foo. Measured, and the
     * opposite of what the name suggests.
     */
    public function is(mixed $kind): bool
    {
        if (\is_int($kind)) { return $this->id === $kind; }
        if (\is_string($kind)) { return $this->text === $kind; }
        if (\is_array($kind)) {
            foreach ($kind as $k) {
                if (\is_int($k) && $this->id === $k) { return true; }
                if (\is_string($k) && $this->text === $k) { return true; }
            }
        }
        return false;
    }

    /** T_CLOSE_TAG and T_INLINE_HTML are NOT ignorable — measured. */
    public function isIgnorable(): bool
    {
        $i = $this->id;
        return $i === __McTokId::T_WHITESPACE
            || $i === __McTokId::T_COMMENT
            || $i === __McTokId::T_DOC_COMMENT
            || $i === __McTokId::T_OPEN_TAG;
    }

    public function getTokenName(): ?string
    {
        if ($this->id < 256) { return $this->text; }
        $names = __mc_tok_names();
        return $names[$this->id] ?? null;
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
