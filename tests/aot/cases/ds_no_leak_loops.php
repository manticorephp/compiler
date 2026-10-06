<?php
use Manticore\Ds\Int32Array;
use Manticore\Ds\Float64Array;

final class E { public function __construct(public readonly string $s) {} }

function churn(int $n): int
{
    $acc = 0;
    for ($r = 0; $r < $n; $r++) {
        $a = new Int32Array(64);
        $a[$r & 63] = $r;
        $a->setSize(128);
        $a->insert(0, 4, 7);
        $a->remove(0, 2);
        $b = clone $a;
        $acc += $b[1] + \count($a->toArray());
        $d = Float64Array::fromArray([1.5, 2.5]);
        $acc += (int)$d[1];
    }
    return $acc;
}

function churnObjects(int $n): int
{
    $f = new SplFixedArray(16);
    $acc = 0;
    for ($r = 0; $r < $n; $r++) {
        $f[$r & 15] = new E(\str_repeat('x', 100) . $r);
        $v = $f[$r & 15];
        $acc += \strlen($v->s);
        if (($r & 1023) === 0) {
            $g = clone $f;
            $g->setSize(4);
            $f->setSize(32);
            $f->setSize(16);
            $acc += \count($g->toArray());
        }
    }
    return $acc;
}

$m0 = memory_get_peak_usage();
echo churn(300000), "\n";
echo churnObjects(1000000), "\n";
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
