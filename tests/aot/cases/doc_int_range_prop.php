<?php
// `int $n` + `@var int<0, max>` is an int property: a value read out of a
// mixed array is unboxed on the store, and arithmetic on it is integer
// arithmetic (sebastian/diff's StrictUnifiedDiffOutputBuilder::$contextLines).

final class Builder
{
    /** @var int<0, max> */
    private int $contextLines;

    /** @var positive-int */
    private int $threshold;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->contextLines = $options['contextLines'];
        $this->threshold = $options['threshold'];
    }

    /** @param int<0, max> $n */
    public function offset(int|false $capture, int $n): int
    {
        $start = $capture - $this->contextLines < 0 ? $capture : $this->contextLines;
        return $start + \min($n, $this->contextLines) + $this->threshold;
    }
}

$b = new Builder(['contextLines' => 3, 'threshold' => 6]);
var_dump($b->offset(1, 4), $b->offset(5, 1));

/** @param positive-int $n @param non-empty-string $s @param class-string $c */
function pseudo($n, $s, $c): string { return \str_repeat($s, $n + 1) . \strlen($c); }
echo pseudo(1, 'ab', \Builder::class), "\n";
