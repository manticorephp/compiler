<?php
// A closure's bare `array` param the body mutates is copied on entry and that copy is the frame's own: returned or dropped, never leaked (VarDumper's Throwable caster).
class P { public $x = 1; public array $t = ['a', 'b']; public string $s = 'q'; }
class Cl
{
    /** @var array<int, callable> */
    public array $casters = [];
    public function cast(object $obj): array
    {
        $a = (array) $obj;
        foreach ($this->casters as $callback) { $a = $callback($obj, $a, false); }
        return $a;
    }
}
function churn(Cl $c, \Closure $direct, int $n): int
{
    $s = 0;
    $in = ['x' => 1, 'k' => 's' . mt_rand(1, 9), 'z' => [1]];
    for ($i = 0; $i < $n; $i++) { $s += \count($c->cast(new P())) + \count($direct($in)); }
    return $s;
}
$c = new Cl();
$c->casters[] = static function (object $o, array $a, bool $nested): array { if (!$nested) { unset($a['x']); } return $a; };
$c->casters[] = static function (object $o, array $a, bool $nested): array { $a['extra'] = $o::class; return $a; };
$direct = static function (array $a): array { unset($a['x']); $a['tail'] = 'tail'; return $a; };
print_r($c->cast(new P()));
print_r($direct(['x' => 1, 'y' => 'two']));
echo churn($c, $direct, 3), "\n";
$before = memory_get_peak_usage();
churn($c, $direct, 100000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
