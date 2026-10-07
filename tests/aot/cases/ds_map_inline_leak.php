<?php
use Manticore\Ds\Map;

final class Box { public function __construct(public string $s) {} }
function churn(int $n): int
{
    /** @var Map<string,string> $m */
    $m = new Map();
    /** @var Map<int,Box> $b */
    $b = new Map();
    $len = 0;
    for ($i = 0; $i < $n; $i++) {
        $k = 'k' . ($i & 255);
        $m->set($k, str_repeat('v', 32) . $i);
        $m[$k . 'x'] = 'w' . $i;
        $len += strlen($m->get($k)) + strlen($m[$k . 'x']);
        if (isset($m[$k]) && $m->has($k . 'x')) { $len++; }
        $b->set($i & 255, new Box('b' . $i));
        $b[$i & 127] = new Box('c' . $i);
        $len += strlen($b->get($i & 255)->s);
        $x = $b[$i & 127]; $len += strlen($x->s);
    }
    return $len;
}
churn(2000);
$before = memory_get_peak_usage();
$len = churn(200000);
echo $len > 0 ? 'ok ' : 'bad ', memory_get_peak_usage() - $before < 4 << 20 ? "flat\n" : "grows\n";
