<?php
/**
 * An rc-managed value stored into an ARRAY ELEMENT is never released when the
 * element is overwritten or unset — the array analogue of tools/prof/propleak.php.
 *
 *   bin/manticore compile tools/prof/elemleak.php -o /tmp/elemleak
 *   for v in obj str arr unset vec; do
 *     /usr/bin/time -l /tmp/elemleak $v 400000 2>&1 | grep 'maximum resident'
 *   done
 *
 * php 8.5 is flat for every variant.
 */

class ELNode
{
    public int $n;
    public string $s;

    public function __construct(int $n, string $s)
    {
        $this->n = $n;
        $this->s = $s;
    }
}

function el_freshObj(int $i): ELNode
{
    return new ELNode($i, 'n');
}

function el_freshStr(int $i): string
{
    return 'value-' . $i . '-tail';
}

/** @return array<int,int> */
function el_freshArr(int $i): array
{
    return [$i, $i + 1, $i + 2, $i + 3];
}

function el_run(string $mode, int $iters): int
{
    /** @var array<string,ELNode> $mo */
    $mo = [];
    /** @var array<string,string> $ms */
    $ms = [];
    /** @var array<string,array<int,int>> $ma */
    $ma = [];
    /** @var array<int,ELNode> $mv */
    $mv = [];
    $sink = 0;
    for ($i = 0; $i < $iters; $i++) {
        if ($mode === 'obj') {
            $mo['k'] = el_freshObj($i);
            $sink += $mo['k']->n;
        } elseif ($mode === 'str') {
            $ms['k'] = el_freshStr($i);
            $sink += \strlen($ms['k']);
        } elseif ($mode === 'arr') {
            $ma['k'] = el_freshArr($i);
            $sink += \count($ma['k']);
        } elseif ($mode === 'unset') {
            $mo['k'] = el_freshObj($i);
            $sink += $mo['k']->n;
            unset($mo['k']);
        } elseif ($mode === 'vec') {
            $mv[0] = el_freshObj($i);
            $sink += $mv[0]->n;
        }
    }
    return $sink;
}

$argvv = $argv;
$mode = $argvv[1] ?? 'obj';
$iters = (int)($argvv[2] ?? 100000);
$r = el_run($mode, $iters);
echo $mode, ' ', $iters, ' sink=', $r, "\n";
