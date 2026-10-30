<?php

namespace Semantic;

/** Byte offsets <-> (line 1-based, byte column 1-based). A line ends at "\n"; a "\r" before it is not part of the line. */
final class LineIndex
{
    /** @var int[] offset of the first byte of each line */
    private array $starts = [0];

    /** @var int[] offset one past the last content byte of each line */
    private array $ends = [];

    private int $len;

    public function __construct(string $text)
    {
        $n = \strlen($text);
        $this->len = $n;
        $from = 0;
        while (true) {
            $nl = \strpos($text, "\n", $from);
            if ($nl === false) { $this->ends[] = $n; break; }
            $this->ends[] = ($nl > $from && $text[$nl - 1] === "\r") ? $nl - 1 : $nl;
            $this->starts[] = $nl + 1;
            $from = $nl + 1;
        }
    }

    /** Column one past the last content byte is valid (end of line / EOF). */
    public function offsetOf(int $line, int $col): ?int
    {
        if ($line < 1 || $line > \count($this->starts) || $col < 1) { return null; }
        $off = $this->starts[$line - 1] + $col - 1;
        return $off > $this->ends[$line - 1] ? null : $off;
    }

    /** @return int[] [line, col]; an offset outside the text is clamped to its start or end */
    public function lineColOf(int $offset): array
    {
        $offset = \max(0, \min($offset, $this->len));
        $lo = 0;
        $hi = \count($this->starts) - 1;
        while ($lo < $hi) {
            $mid = \intdiv($lo + $hi + 1, 2);
            if ($this->starts[$mid] <= $offset) { $lo = $mid; } else { $hi = $mid - 1; }
        }
        return [$lo + 1, $offset - $this->starts[$lo] + 1];
    }
}
