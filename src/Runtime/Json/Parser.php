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

    private function parseString(): string
    {
        $out = '';
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '"') {
            return $out;
        }
        $this->pos = $this->pos + 1; // skip opening '"'
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
                if ($e === 'n') { $out = $out . "\n"; }
                elseif ($e === 't') { $out = $out . "\t"; }
                elseif ($e === 'r') { $out = $out . "\r"; }
                else { $out = $out . $e; }
                $this->pos = $this->pos + 1;
                continue;
            }
            $out = $out . $c;
            $this->pos = $this->pos + 1;
        }
        return $out;
    }

    private function parseNumber(): mixed
    {
        $start = $this->pos;
        $isFloat = false;
        while ($this->pos < $this->len) {
            $b = \ord($this->src[$this->pos]);
            if ($b === 45 || $b === 43 || ($b >= 48 && $b <= 57)) {
                $this->pos = $this->pos + 1;
            } elseif ($b === 46 || $b === 101 || $b === 69) { // '.' 'e' 'E'
                $isFloat = true;
                $this->pos = $this->pos + 1;
            } else {
                break;
            }
        }
        $tok = \substr($this->src, $start, $this->pos - $start);
        if ($isFloat) { return (float)$tok; }
        return (int)$tok;
    }
}
