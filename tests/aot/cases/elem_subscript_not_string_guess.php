<?php
declare(strict_types=1);

// A subscripted element (`foreach ($diff as $entry) { $entry[1] … }`) is no
// evidence the param is vec[string]: a list of `[line, type]` tuples reads the
// same. The guess stuck because the argument arrives erased through an
// interface, so no call site refuted it — sebastian/diff's
// StrictUnifiedDiffOutputBuilder read every tuple as a string and
// php-cs-fixer --diff printed one empty hunk.

interface Builder
{
    public function getDiff(array $diff): string;
}

final class Hunks implements Builder
{
    public function getDiff(array $diff): string
    {
        return $this->walk($diff);
    }

    private function walk(array $diff): string
    {
        $out = '';
        $same = 0;
        foreach ($diff as $i => $entry) {
            if (0 === $entry[1]) {
                $same++;
                continue;
            }
            $out .= $i . ($entry[1] === 1 ? '+' : '-') . $entry[0];
        }
        return $out . 'same=' . $same . "\n";
    }
}

final class Chars
{
    /** A genuine string-offset use keeps working. */
    public static function firsts(array $lines): string
    {
        $o = '';
        foreach ($lines as $l) {
            if ($l[0] === '#') { $o .= $l[1]; }
        }
        return $o;
    }
}

function mk(): array
{
    $d = [];
    foreach (explode(',', 'a,b,c') as $t) { $d[] = [$t . "\n", 0]; }
    $d[] = ["x\n", 2];
    $d[] = ["y\n", 1];
    $d[] = ["z\n", 0];
    return $d;
}

function run(Builder $b): void { echo $b->getDiff(mk()); }

run(new Hunks());
echo Chars::firsts(['#a', 'b', '#c']), "\n";
