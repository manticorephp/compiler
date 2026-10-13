<?php

use Random\Engine\Mt19937;
use Random\Engine\PcgOneseq128XslRr64;
use Random\Engine\Secure;
use Random\Engine\Xoshiro256StarStar;
use Random\IntervalBoundary;
use Random\Randomizer;

final class Lcg implements Random\Engine
{
    private int $x = 12345;
    public function __construct(private int $size) {}
    public function generate(): string
    {
        $o = '';
        for ($i = 0; $i < $this->size; $i++) {
            $this->x = ($this->x * 1103515245 + 12345) & 0x7fffffff;
            $o .= chr(($this->x >> 8) & 255);
        }
        return $o;
    }
}

function dump(Randomizer $r, string $tag): void
{
    $o = [];
    $o[] = $r->nextInt();
    $o[] = $r->nextFloat();
    $o[] = $r->getInt(0, 99);
    $o[] = $r->getInt(-5, 5);
    $o[] = $r->getInt(0, 0xFFFFFFFF);
    $o[] = $r->getInt(0, PHP_INT_MAX);
    $o[] = $r->getInt(PHP_INT_MIN, PHP_INT_MAX);
    $o[] = $r->getInt(-1000000000000, 1000000000000);
    $o[] = $r->getInt(PHP_INT_MIN, 5);
    $o[] = bin2hex($r->getBytes(5));
    $o[] = bin2hex($r->getBytes(21));
    $o[] = $r->getBytesFromString('abcdef', 12);
    $o[] = $r->getBytesFromString(str_repeat('xyz0123456789', 30), 12);
    $o[] = $r->shuffleArray(range(1, 12));
    $o[] = $r->shuffleBytes('abcdefghij');
    $o[] = $r->pickArrayKeys(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5], 2);
    $o[] = $r->pickArrayKeys(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5], 4);
    $o[] = $r->pickArrayKeys(['a' => 1, 'b' => 2, 'c' => 3], 1);
    foreach (IntervalBoundary::cases() as $b) {
        foreach ([[0.0, 1.0], [-1.0, 1.0], [-3.5, -0.25], [1e10, 1e10 + 7], [-1e300, 1e300], [-2.0, 0.0]] as [$lo, $hi]) {
            $o[] = $r->getFloat($lo, $hi, $b);
        }
    }
    echo $tag, ': ', json_encode($o), "\n";
}

dump(new Randomizer(new Mt19937(12345)), 'mt');
dump(new Randomizer(new Mt19937(0)), 'mt0');
dump(new Randomizer(new Xoshiro256StarStar(987654321987)), 'xo');
dump(new Randomizer(new Xoshiro256StarStar(str_repeat("\x01\x02\x03\x04\x05\x06\x07\x08", 4))), 'xos');
dump(new Randomizer(new PcgOneseq128XslRr64(42)), 'pcg');
dump(new Randomizer(new PcgOneseq128XslRr64(-7)), 'pcgn');
dump(new Randomizer(new PcgOneseq128XslRr64('0123456789abcdef')), 'pcgs');
foreach ([1, 3, 4, 8] as $n) {
    $r = new Randomizer(new Lcg($n));
    echo 'user', $n, ': ', json_encode([
        $r->nextInt(), $r->nextFloat(), $r->getInt(0, 1000), $r->getInt(0, PHP_INT_MAX),
        bin2hex($r->getBytes(11)), $r->getBytesFromString('abcde', 9),
        $r->shuffleArray([1, 2, 3, 4, 5, 6, 7]), $r->pickArrayKeys([1, 2, 3, 4, 5, 6], 3), $r->getFloat(0.0, 1.0),
    ]), "\n";
}

// jump / serialize state
$x = new Xoshiro256StarStar(77);
$x->jump();
echo json_encode($x->__serialize()), "\n";
$x->jumpLong();
echo json_encode($x->__serialize()), "\n";
$p = new PcgOneseq128XslRr64(5);
$p->jump(1000003);
echo json_encode($p->__serialize()), "\n";
$p->jump(PHP_INT_MAX);
echo json_encode($p->__serialize()), "\n";
$m = new Mt19937(77);
$m->generate();
$m2 = new Mt19937(1);
$m2->__unserialize($m->__serialize());
echo bin2hex($m2->generate()), ' ', bin2hex($m->generate()), "\n";
echo count($m->__serialize()[1]), ' ', array_keys($m->__debugInfo())[0], "\n";
$u = unserialize(serialize(new Randomizer(new Xoshiro256StarStar(3))));
echo $u->getInt(1, 1000000), ' ', (new Randomizer(new Xoshiro256StarStar(3)))->getInt(1, 1000000), "\n";

// secure engine + default randomizer: structural only
$s = new Randomizer();
echo get_class($s->engine), ' ', strlen((new Secure())->generate()), ' ', strlen($s->getBytes(13)), "\n";
$v = $s->getInt(5, 9);
echo ($v >= 5 && $v <= 9) ? 'int-ok' : 'int-bad', "\n";
$f = $s->nextFloat();
echo ($f >= 0.0 && $f < 1.0) ? 'float-ok' : 'float-bad', "\n";
$l = lcg_value();
echo ($l > 0.0 && $l < 1.0) ? 'lcg-ok' : 'lcg-bad', "\n";
echo (new Secure()) instanceof Random\CryptoSafeEngine ? 'crypto' : 'plain', "\n";

// errors
$r = new Randomizer(new Xoshiro256StarStar(1));
$bad = [
    fn() => $r->getInt(5, 1),
    fn() => $r->getBytes(0),
    fn() => $r->getBytesFromString('', 3),
    fn() => $r->getBytesFromString('a', 0),
    fn() => $r->pickArrayKeys([], 1),
    fn() => $r->pickArrayKeys([1], 2),
    fn() => $r->getFloat(1.0, 1.0),
    fn() => $r->getFloat(NAN, 1.0),
    fn() => $r->getFloat(0.0, INF),
    fn() => $r->getFloat(0.0, 0.0, IntervalBoundary::OpenOpen),
    fn() => new Xoshiro256StarStar('abc'),
    fn() => new Xoshiro256StarStar(str_repeat("\0", 32)),
    fn() => new PcgOneseq128XslRr64('abc'),
    fn() => (new PcgOneseq128XslRr64(1))->jump(-1),
    fn() => new Mt19937(1, 7),
    fn() => serialize(new Secure()),
    fn() => (new Randomizer(new Lcg(0)))->nextInt(),
];
foreach ($bad as $f) {
    try {
        $f();
        echo "no error\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    throw new Random\RandomException('x');
} catch (Exception $e) {
    echo get_class($e), ' ', get_parent_class(new Random\BrokenRandomEngineError('y')), ' ', get_parent_class(new Random\RandomError('z')), "\n";
}
