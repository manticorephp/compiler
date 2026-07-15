<?php

namespace Runtime\Json;

/**
 * Minimal recursive-descent JSON parser backing the global `json_decode`.
 *
 * Position lives in instance state (not by-ref params) — the self-host
 * compiler drops writes through `&$pos` across recursive calls, so a class
 * with a mutable `$pos` field is the working idiom (same shape as the
 * Lexer/Parser). Objects decode to assoc arrays, arrays to vecs; scalars to
 * int/float/string/bool/null. Enough for `manticore.json` and config files;
 * not a strict validator (malformed input degrades, it does not throw).
 */
final class Parser
{
    private string $src;
    private int $pos;
    private int $len;

    public function __construct(string $src)
    {
        $this->src = $src;
        $this->pos = 0;
        $this->len = \strlen($src);
    }

    public function parse(): mixed
    {
        $this->skipWs();
        return $this->parseValue();
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->len) {
            $b = \ord($this->src[$this->pos]);
            // space, tab, LF, CR
            if ($b === 32 || $b === 9 || $b === 10 || $b === 13) {
                $this->pos = $this->pos + 1;
            } else {
                break;
            }
        }
    }

    private function parseValue(): mixed
    {
        $this->skipWs();
        if ($this->pos >= $this->len) { return null; }
        $c = $this->src[$this->pos];
        if ($c === '{') { return $this->parseObject(); }
        if ($c === '[') { return $this->parseArray(); }
        if ($c === '"') { return $this->parseString(); }
        if ($c === 't') { $this->pos = $this->pos + 4; return true; }
        if ($c === 'f') { $this->pos = $this->pos + 5; return false; }
        if ($c === 'n') { $this->pos = $this->pos + 4; return null; }
        return $this->parseNumber();
    }

    /** @return array<string, mixed> */
    private function parseObject(): array
    {
        /** @var array<string, mixed> $obj */
        $obj = [];
        $this->pos = $this->pos + 1; // skip '{'
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === '}') {
            $this->pos = $this->pos + 1;
            return $obj;
        }
        while ($this->pos < $this->len) {
            $this->skipWs();
            $key = $this->parseString();
            $this->skipWs();
            if ($this->pos < $this->len && $this->src[$this->pos] === ':') {
                $this->pos = $this->pos + 1;
            }
            $val = $this->parseValue();
            $obj[$key] = $val;
            $this->skipWs();
            if ($this->pos < $this->len && $this->src[$this->pos] === ',') {
                $this->pos = $this->pos + 1;
                continue;
            }
            break;
        }
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === '}') {
            $this->pos = $this->pos + 1;
        }
        return $obj;
    }

    /** @return mixed[] */
    private function parseArray(): array
    {
        /** @var mixed[] $arr */
        $arr = [];
        $this->pos = $this->pos + 1; // skip '['
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === ']') {
            $this->pos = $this->pos + 1;
            return $arr;
        }
        while ($this->pos < $this->len) {
            $val = $this->parseValue();
            $arr[] = $val;
            $this->skipWs();
            if ($this->pos < $this->len && $this->src[$this->pos] === ',') {
                $this->pos = $this->pos + 1;
                continue;
            }
            break;
        }
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === ']') {
            $this->pos = $this->pos + 1;
        }
        return $arr;
    }

    /**
     * Scan a string literal by RUNS, not by bytes. `strcspn` walks to the next
     * `"` or `\` in one bounded pass and the run is taken with a single
     * `substr`; the byte loop it replaces paid a 1-char string temp (malloc +
     * append) for EVERY byte of every key and value in the document.
     *
     * The unescaped run is the overwhelmingly common case, so it returns its
     * substr directly — no accumulator, no append. Only a literal that actually
     * carries a `\` escape falls into the run+escape loop below.
     */
    private function parseString(): string
    {
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '"') {
            return '';
        }
        $this->pos = $this->pos + 1; // skip opening '"'
        $start = $this->pos;
        $n = \strcspn($this->src, "\"\\", $start);
        $p = $start + $n;
        if ($p >= $this->len) { // unterminated: take what is there
            $this->pos = $p;
            return \substr($this->src, $start, $n);
        }
        if ($this->src[$p] === '"') { // no escapes — one substr and done
            $this->pos = $p + 1;
            return \substr($this->src, $start, $n);
        }
        $out = \substr($this->src, $start, $n);
        $this->pos = $p;
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === '"') {
                $this->pos = $this->pos + 1;
                break;
            }
            if ($c === '\\') {
                $this->pos = $this->pos + 1;
                if ($this->pos >= $this->len) { break; }
                $e = $this->src[$this->pos];
                if ($e === 'u') {
                    // \uXXXX — 4 hex digits → the codepoint's UTF-8 bytes.
                    // Advances past the digits itself.
                    $cp = 0; $k = 0;
                    $this->pos = $this->pos + 1;
                    while ($k < 4 && $this->pos < $this->len) {
                        $hv = $this->hexDigit($this->src[$this->pos]);
                        if ($hv < 0) { break; }
                        $cp = $cp * 16 + $hv;
                        $this->pos = $this->pos + 1;
                        $k = $k + 1;
                    }
                    $out = $out . $this->utf8Encode($cp);
                } else {
                    if ($e === 'n') { $out = $out . "\n"; }
                    elseif ($e === 't') { $out = $out . "\t"; }
                    elseif ($e === 'r') { $out = $out . "\r"; }
                    elseif ($e === 'b') { $out = $out . \chr(8); }
                    elseif ($e === 'f') { $out = $out . \chr(12); }
                    else { $out = $out . $e; } // \\ \" \/ and any other
                    $this->pos = $this->pos + 1;
                }
            }
            // Past the escape: take the next unescaped run in one go.
            $r = \strcspn($this->src, "\"\\", $this->pos);
            if ($r > 0) {
                $out = $out . \substr($this->src, $this->pos, $r);
                $this->pos = $this->pos + $r;
            }
        }
        return $out;
    }

    /** Hex-digit value of `$c`, or -1. */
    private function hexDigit(string $c): int
    {
        $o = \ord($c);
        if ($o >= 48 && $o <= 57) { return $o - 48; }
        if ($o >= 97 && $o <= 102) { return $o - 97 + 10; }
        if ($o >= 65 && $o <= 70) { return $o - 65 + 10; }
        return -1;
    }

    /** UTF-8 bytes for a BMP codepoint (1-3 bytes; matches PHP json_decode). */
    private function utf8Encode(int $cp): string
    {
        if ($cp < 128) { return \chr($cp); }
        if ($cp < 2048) {
            return \chr(192 + (($cp >> 6) & 31)) . \chr(128 + ($cp & 63));
        }
        return \chr(224 + (($cp >> 12) & 15))
            . \chr(128 + (($cp >> 6) & 63))
            . \chr(128 + ($cp & 63));
    }

    /**
     * An integer accumulates straight out of the digit scan — the `substr` the
     * token used to need was a malloc per number. A float still materializes the
     * token (strtod needs the bytes), and so does an integer long enough to
     * overflow, which keeps `strtol`'s saturation instead of a wrapping
     * accumulator.
     */
    private function parseNumber(): mixed
    {
        $start = $this->pos;
        $isFloat = false;
        $neg = false;
        $iv = 0;
        $digits = 0;
        if ($this->pos < $this->len && \ord($this->src[$this->pos]) === 45) { // '-'
            $neg = true;
            $this->pos = $this->pos + 1;
        }
        while ($this->pos < $this->len) {
            $b = \ord($this->src[$this->pos]);
            if ($b >= 48 && $b <= 57) {
                $iv = $iv * 10 + ($b - 48);
                $digits = $digits + 1;
                $this->pos = $this->pos + 1;
            } elseif ($b === 46 || $b === 101 || $b === 69 // '.' 'e' 'E'
                || $b === 43 || $b === 45) {               // exponent sign
                $isFloat = true;
                $this->pos = $this->pos + 1;
            } else {
                break;
            }
        }
        if ($isFloat || $digits > 18) {
            $tok = \substr($this->src, $start, $this->pos - $start);
            if ($isFloat) { return (float)$tok; }
            return (int)$tok;
        }
        return $neg ? -$iv : $iv;
    }
}
