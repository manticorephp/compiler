<?php
// Reflection and dynamic calls that omit optional arguments get each default as written: constant
// expressions in the declaring class's scope, enum cases, a fresh `new` per call, and a callee that
// counts its arguments (its own file, a_cnt.php, keeps per-arity arms) still sees how many it was given.
enum Mode: string { case Fast = 'fast'; case Slow = 'slow'; }
class Box { public static int $made = 0; public function __construct(public string $tag = 'box') { self::$made++; } }
class Svc {
    const LIMIT = 7;
    public function run(int $a, string $s = 'x', ?Box $b = null, int $lim = self::LIMIT, Mode $m = Mode::Slow,
                        array $opts = ['k' => 1], float $f = 2.5, Box $nb = new Box('fresh')): string {
        return "$a $s " . ($b === null ? 'null' : $b->tag) . " $lim {$m->value} " . json_encode($opts) . " $f {$nb->tag}";
    }
    public static function stat(string $p = 'P' . 'Q', int ...$rest): string { return $p . ':' . count($rest); }
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
$rc = new ReflectionMethod(Cnt::class, "counted"); $k = new Cnt();
echo $rc->invoke($k, 1), " | ", $rc->invoke($k, 1, 2), "\n";
$c = \str_repeat('c', 1) . 'ounted';
echo $k->$c(...[3]), "\n";
echo (new ReflectionClass(Box::class))->newInstance()->tag, ' ', (new ReflectionClass(Box::class))->newInstanceArgs(['n'])->tag, "\n";
echo 'boxes made: ', Box::$made, "\n";
