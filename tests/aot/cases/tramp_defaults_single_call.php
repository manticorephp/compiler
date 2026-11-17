<?php
// Reflection and dynamic calls that omit optional arguments get each default as written: constant
// expressions in the declaring class's scope, enum cases, a fresh `new` per call, and a callee that
// counts its arguments still sees how many it was given — while its SIBLINGS in the same class take the
// single call (the `boxes made` lines: the per-arity arms would build the `new Box` default twice, #713).
enum Mode: string { case Fast = 'fast'; case Slow = 'slow'; }
class Box { public static int $made = 0; public function __construct(public string $tag = 'box') { self::$made++; } }
trait Counts {
    public function tcount(int $a, int $b = 5): string { return 'trait n=' . func_num_args(); }
}
class Svc {
    use Counts { tcount as tc2; }
    const LIMIT = 7;
    public function run(int $a, string $s = 'x', ?Box $b = null, int $lim = self::LIMIT, Mode $m = Mode::Slow,
                        array $opts = ['k' => 1], float $f = 2.5, Box $nb = new Box('fresh')): string {
        return "$a $s " . ($b === null ? 'null' : $b->tag) . " $lim {$m->value} " . json_encode($opts) . " $f {$nb->tag}";
    }
    public static function stat(string $p = 'P' . 'Q', int ...$rest): string { return $p . ':' . count($rest); }
    public function counted(int $a, int $b = 5): string { return $a . '+' . $b . ' n=' . func_num_args(); }
}
$s = new Svc();
$rm = new ReflectionMethod(Svc::class, 'run');
echo $rm->invoke($s, 1), "\n";
echo $rm->invoke($s, 2, 'y'), "\n";
echo $rm->invokeArgs($s, [3, 'z', new Box('given'), 9, Mode::Fast, [], 1.0]), "\n";
echo 'boxes made: ', Box::$made, "\n";
$m = \str_repeat('r', 1) . 'un';
echo $s->$m(...[4]), "\n";
echo (new ReflectionMethod(Svc::class, 'stat'))->invoke(null), "\n";
$rc = new ReflectionMethod(Svc::class, 'counted');
echo $rc->invoke($s, 1), ' | ', $rc->invoke($s, 1, 2), "\n";
$c = \str_repeat('c', 1) . 'ounted';
echo $s->$c(...[3]), "\n";
echo (new ReflectionMethod(Svc::class, 'tcount'))->invoke($s, 1), ' | ', (new ReflectionMethod(Svc::class, 'tc2'))->invoke($s, 1), "\n";
$t = \str_repeat('t', 1) . 'c2';
echo $s->$t(...[1]), "\n";
echo (new ReflectionClass(Box::class))->newInstance()->tag, ' ', (new ReflectionClass(Box::class))->newInstanceArgs(['n'])->tag, "\n";
echo 'boxes made: ', Box::$made, "\n";
