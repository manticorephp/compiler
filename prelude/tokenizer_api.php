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

/**
 * The colour php paints a token, as one of 'd' (default), 'k' (keyword), 's'
 * (string), 'c' (comment) or 'h' (inline HTML, which is emitted with NO span at
 * all because highlight.html equals the <code> element's own colour).
 *
 * The table is php's, measured against `highlight_string` rather than read out
 * of the spec: everything that is not a literal, an identifier, a tag or a magic
 * constant paints KEYWORD — every operator and every single-character token
 * included, which is why `= ` and `; ` come out the same green as `function`.
 * The `"` delimiter of an interpolated string is the one single-character token
 * that paints as a STRING.
 */
function __mc_hl_color(int $id, string $text): string
{
    if ($id === T_INLINE_HTML) { return 'h'; }
    if ($id === T_COMMENT || $id === T_DOC_COMMENT) { return 'c'; }
    if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) { return 's'; }
    if ($id < 256) { return $text === '"' || $text === '`' ? 's' : 'k'; }
    if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO || $id === T_CLOSE_TAG
        || $id === T_VARIABLE || $id === T_STRING || $id === T_LNUMBER || $id === T_DNUMBER
        || $id === T_NUM_STRING || $id === T_STRING_VARNAME
        || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_RELATIVE
        || $id === T_LINE || $id === T_FILE || $id === T_DIR || $id === T_NS_C
        || $id === T_CLASS_C || $id === T_FUNC_C || $id === T_METHOD_C || $id === T_TRAIT_C
        || $id === T_PROPERTY_C) {
        return 'd';
    }

    return 'k';
}

/**
 * The markup behind highlight_string / highlight_file.
 *
 * Two rules carry the whole format. WHITESPACE has no colour of its own — it
 * keeps whatever span is open, which is why `$x ` swallows its trailing space
 * and `;\nfunction ` is a single green run: php only closes a span when the
 * colour actually CHANGES. And inline HTML closes the span and emits no span of
 * its own, because its ini colour is the one already on the <code> element.
 *
 * Escaping is `&`, `<`, `>` and nothing else — a quote inside a string literal
 * stays a quote, as php leaves it.
 */
function __mc_highlight(string $code): string
{
    $hex = ['d' => '#0000BB', 'k' => '#007700', 's' => '#DD0000', 'c' => '#FF8000'];
    $out = '<pre><code style="color: #000000">';
    $open = '';
    foreach (\token_get_all($code) as $t) {
        if (\is_array($t)) {
            $id = $t[0];
            $text = $t[1];
        } else {
            $id = \ord($t[0]);
            $text = $t;
        }
        $c = __mc_hl_color($id, $text);
        // Whitespace never opens a span; it rides the one already open. At the
        // very start there is none, and php paints it default.
        if ($id === T_WHITESPACE && $open !== '') { $c = $open; }
        elseif ($id === T_WHITESPACE) { $c = 'd'; }
        $esc = \str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        if ($c === 'h') {
            if ($open !== '') { $out = $out . '</span>'; $open = ''; }
            $out = $out . $esc;
            continue;
        }
        if ($c !== $open) {
            if ($open !== '') { $out = $out . '</span>'; }
            $out = $out . '<span style="color: ' . $hex[$c] . '">';
            $open = $c;
        }
        $out = $out . $esc;
    }
    if ($open !== '') { $out = $out . '</span>'; }

    return $out . '</code></pre>';
}

/**
 * `highlight_string($string, $return)` — php's syntax highlighter. Returns the
 * markup when $return is true, otherwise prints it and returns true.
 *
 * @return string|bool
 */
function highlight_string(string $string, bool $return = false)
{
    $html = __mc_highlight($string);
    if ($return) { return $html; }
    echo $html;

    return true;
}

/**
 * `highlight_file($filename, $return)`. php WARNS and returns false when the
 * file cannot be read; this build returns false silently — the documented
 * no-warnings divergence.
 *
 * @return string|bool
 */
function highlight_file(string $filename, bool $return = false)
{
    if (!\is_file($filename)) { return false; }
    $code = \file_get_contents($filename);
    if ($code === false) { return false; }

    return \highlight_string($code, $return);
}

/** `show_source` is php's own alias of highlight_file. @return string|bool */
function show_source(string $filename, bool $return = false)
{
    return \highlight_file($filename, $return);
}
