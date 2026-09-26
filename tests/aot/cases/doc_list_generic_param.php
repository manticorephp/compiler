<?php
// `list<T>`, `non-empty-list<T>` and `non-empty-array<K, V>` docblocks type the
// parameter like `T[]` / `array<K, V>`: a string use of one field must not
// retype the whole element (sebastian/diff's StrictUnifiedDiffOutputBuilder).

interface Builder
{
    /** @param list<array{0: mixed, 1: int}> $diff */
    public function getDiff(array $diff): string;
}

final class Hunks implements Builder
{
    /** @param non-empty-list<array{0: mixed, 1: int}> $diff */
    private function write(mixed $output, array $diff): string
    {
        $upperLimit = \count($diff);
        if (0 === $diff[$upperLimit - 1][1]) {
            $lc = \substr($diff[$upperLimit - 1][0], -1);
            if ("\n" !== $lc) {
                \array_splice($diff, $upperLimit, 0, [["\n\\ No newline at end of file\n", 4]]);
            }
        } else {
            $toFind = [1 => true, 2 => true];
            for ($i = $upperLimit - 1; $i >= 0; $i--) {
                if (isset($toFind[$diff[$i][1]])) {
                    unset($toFind[$diff[$i][1]]);
                    if ($toFind === []) { break; }
                }
            }
        }
        $out = $output;
        foreach ($diff as $i => $entry) {
            $out .= (0 === $entry[1] ? ' ' : '+') . \rtrim($entry[0]);
        }
        return $out;
    }

    /** @param list<array{0: mixed, 1: int}> $diff */
    public function getDiff(array $diff): string { return $this->write(">", $diff); }

    /** @param list<int> $xs */
    public function sum(array $xs): int { $s = 0; foreach ($xs as $x) { $s += $x; } return $s; }

    /** @param non-empty-array<string, int> $m */
    public function keys(array $m): string { $o = ''; foreach ($m as $k => $v) { $o .= $k . '=' . ($v + 1) . ';'; } return $o; }
}

$h = new Hunks();
$b = $h instanceof Builder ? $h : null;
echo $b->getDiff([["a\n", 0], ["b\n", 1], ["c", 0]]), "\n";
echo $b->getDiff([["a\n", 0], ["b\n", 1]]), "\n";
echo $h->sum([1, 2, 3]), "\n";
echo $h->keys(['x' => 1, 'y' => 2]), "\n";
