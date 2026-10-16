<?php
class H
{
    public array $a;
    public array $b;
    public array $c;
    public function __construct(string|array $s, mixed $m, string $t)
    {
        $this->a = (array) $s;
        $this->b = (array) $m;
        $this->c = (array) $t;
    }
}
function mk(string|array $s, mixed $m, string $t): H { return new H($s, $m, $t); }

function wrap(string|array $s, mixed $m, string $t): int { $a = (array) $s; $b = (array) $m; $c = (array) $t; return count($a) + count($b) + count($c); }
$h = mk(str_repeat('s', 12) . strlen('ab'), str_repeat('m', 12) . strlen('ab'), str_repeat('t', 12) . strlen('ab'));
$noise = [];
for ($i = 0; $i < 8; $i++) { $noise[] = str_repeat('n', 12) . $i; }
echo $h->a[0], ' ', $h->b[0], ' ', $h->c[0], "\n";
$x = [str_repeat('p', 12) . strlen('ab')];
foreach ([1, 2.5, true, null, 'str'] as $v) { var_dump((array) $v); }
$before = memory_get_peak_usage();
for ($i = 0; $i < 300000; $i++) { $q = wrap(str_repeat('s', 40) . $i, $i, 't' . $i); }
echo memory_get_peak_usage() - $before < 8000000 ? "flat\n" : "leak\n";
