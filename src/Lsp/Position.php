<?php

namespace Lsp;

/** LSP columns (0-based UTF-16 units) <-> core columns (1-based bytes). A stray or truncated UTF-8 byte counts as one byte and one unit. */
final class Position
{
    public static function toCore(string $lineText, int $utf16Col): int
    {
        $n = \strlen($lineText);
        $i = 0;
        $u = 0;
        while ($i < $n && $u < $utf16Col) {
            $l = self::seqLen($lineText, $i, $n);
            $w = $l === 4 ? 2 : 1;
            if ($u + $w > $utf16Col) { break; }
            $u += $w;
            $i += $l;
        }
        return $i + 1;
    }

    public static function toLsp(string $lineText, int $byteCol): int
    {
        $n = \strlen($lineText);
        $target = \min($byteCol - 1, $n);
        $i = 0;
        $u = 0;
        while ($i < $target) {
            $l = self::seqLen($lineText, $i, $n);
            $u += $l === 4 ? 2 : 1;
            $i += $l;
        }
        return $u;
    }

    /** Text of 1-based $line1 without its line ending; '' when the line does not exist. */
    public static function lineText(string $text, int $line1): string
    {
        $from = 0;
        for ($k = 1; $k < $line1; $k++) {
            $nl = \strpos($text, "\n", $from);
            if ($nl === false) { return ''; }
            $from = $nl + 1;
        }
        $nl = \strpos($text, "\n", $from);
        $end = $nl === false ? \strlen($text) : $nl;
        if ($nl !== false && $end > $from && $text[$end - 1] === "\r") { $end--; }
        return \substr($text, $from, $end - $from);
    }

    /** Byte offset of an LSP (line, UTF-16 character) in $text; a line past the end clamps to the end, a character past the line end to the line end. */
    public static function offsetOf(string $text, int $line, int $char): int
    {
        $from = 0;
        for ($k = 0; $k < $line; $k++) {
            $nl = \strpos($text, "\n", $from);
            if ($nl === false) { return \strlen($text); }
            $from = $nl + 1;
        }
        $nl = \strpos($text, "\n", $from);
        $end = $nl === false ? \strlen($text) : $nl;
        if ($nl !== false && $end > $from && $text[$end - 1] === "\r") { $end--; }
        return $from + self::toCore(\substr($text, $from, $end - $from), $char) - 1;
    }

    /** $text with the LSP range replaced (an incremental didChange entry). */
    public static function applyChange(string $text, int $sl, int $sc, int $el, int $ec, string $new): string
    {
        $a = self::offsetOf($text, $sl, $sc);
        $b = \max($a, self::offsetOf($text, $el, $ec));
        return \substr($text, 0, $a) . $new . \substr($text, $b);
    }

    private static function seqLen(string $s, int $i, int $n): int
    {
        $b = \ord($s[$i]);
        $l = $b < 0xC0 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : ($b < 0xF8 ? 4 : 1)));
        if ($i + $l > $n) { return 1; }
        for ($k = 1; $k < $l; $k++) {
            if ((\ord($s[$i + $k]) & 0xC0) !== 0x80) { return 1; }
        }
        return $l;
    }
}
