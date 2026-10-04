<?php
// A first-class callable whose target arity lowering cannot see must forward
// every argument, not just the first.

class Pool
{
    public function two(string $p, string $w): void { echo "[$p] [$w]\n"; }
    public function num(string $p, int $w, int $x = 9): void { echo "[$p] [$w] [$x]\n"; }
    public static function st(string $a, string $b = 'dflt'): void { echo "st [$a] [$b]\n"; }
    protected function crash(string $id, string $reason): string { return $id . ':' . $reason; }

    public function viaThis(): void
    {
        $c = $this->crash(...);
        echo $c('w1', 'boom'), "\n";
        $n = $this->num(...);
        $n('t', 4);
        $n('t', 4, 5);
        $s = static::st(...);
        $s('a', 'b');
        $s('a');
        $s2 = self::st(...);
        $s2('x', 'y');
    }
}

function make(): Pool { return new Pool(); }

$r = new Pool();
$c = $r->two(...);
$c('a', 'b');
$d = $r->num(...);
$d('a', 7);
(make()->two(...))('m', 'n');
$e = Pool::st(...);
$e('p', 'q');
$r->viaThis();
$str = str_replace(...);
echo $str('a', 'b', 'banana'), "\n";
$cl = \Closure::fromCallable([$r, 'two']);
$cl('f', 'g');
