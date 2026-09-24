<?php

// A by-ref parameter the call omits is backed by a throwaway slot that holds
// the default; the callee writes its own +1 through it, and that write is the
// call site's to drop — php discards it with the temporary. Nothing released
// it: `preg_match($re, $s)` leaked its whole `$matches` on every hit (~160 B),
// and a static or constructor call with an omitted by-ref default passed the
// default VALUE where an address belonged, so the callee's write SIGSEGVed.
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the bound is 3 MB over 100 000 calls. @serial: a memory
// measurement.

/** @param callable(int): int $body */
function measure(string $label, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 2000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < 100000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

/** @param ?string[] $out */
function fill(int $n, ?array &$out = null): int
{
    $out = ['a' . $n, 'b' . $n];
    return 1;
}

final class Box
{
    public int $v;

    /** @param ?string[] $out */
    public function __construct(int $v = 0, ?array &$out = null)
    {
        $this->v = $v;
        $out = ['c' . $v];
    }

    /** @param ?string[] $out */
    public static function make(string $s, ?array &$out = null): int
    {
        $out = [$s . '!', $s . '?'];
        return 2;
    }

    /** @param ?string[] $out */
    public function put(string $s, ?array &$out = null): int
    {
        $out = [$s . '#'];
        return 3;
    }
}

$subject = str_repeat('z', 200);
measure('preg_match hit', function (int $i) use ($subject): int {
    return preg_match('/z(z)/', $subject);
});
measure('preg_match named', function (int $i) use ($subject): int {
    return preg_match('/(?<n>z)(y)?/', $subject);
});
measure('function', function (int $i): int {
    return fill($i);
});
measure('static method', function (int $i): int {
    return Box::make('s' . $i);
});
measure('constructor', function (int $i): int {
    $b = new Box($i % 7);
    return $b->v;
});
measure('method', function (int $i): int {
    $b = new Box(1);
    return $b->put('m' . $i);
});

fill(5, $got);
echo implode(',', $got), "\n";
preg_match('/z(z)/', $subject, $m);
echo count($m), ' ', $m[1], "\n";
new Box(4, $c);
echo $c[0], ' ', Box::make('x', $s), ' ', $s[1], "\n";

// Every callable form reaches the same pad: the closure ABI carries no arity,
// so an omitted trailing param was whatever the register held — `$f($i)` on a
// by-ref `&$m = null` wrote through garbage (SIGSEGV) and a by-value default
// read 0. A closure param's type comes from its hint alone and a bare `?array`
// is erased (nothing to release it by), so the leak probes use `string &$s`;
// the `?array` closure pins the crash and the value only.

final class Inv
{
    /** @param ?string[] $out */
    public function __invoke(int $n, int $k = 2, ?array &$out = null): int
    {
        $out = ['i' . $n];
        return $k;
    }
}

final class Holder
{
    public \Closure $cb;

    public function __construct()
    {
        $this->cb = function (int $n, string &$s = ''): int {
            $s = 'p' . $n;
            return 4;
        };
    }

    public function run(int $n): int
    {
        return ($this->cb)($n);
    }
}

final class Fwd
{
    const D = 9;

    public static function st(int $n, int $d = self::D, string &$s = ''): int
    {
        $s = 's' . $n;
        return $d;
    }

    public function me(int $n, int $d = self::D, string &$s = ''): int
    {
        $s = 'm' . $n;
        return $d + 1;
    }
}

function fwd(int $n, int $d = 3, string &$s = ''): int
{
    $s = 'f' . $n;
    return $d;
}

$cl = function (int $n, int $k = 5, string &$s = ''): int {
    $s = 'c' . $n;
    return $k;
};
$ar = fn (int $n, int $k = 6, string &$s = ''): int => ($s = 'a' . $n) !== '' ? $k : 0;
$nul = function (int $n, ?array &$m = null): int {
    $m = ['n' . $n];
    return 7;
};
$inv = new Inv();
$hold = new Holder();
$fcc = fwd(...);
$fst = Fwd::st(...);
$fme = (new Fwd())->me(...);
measure('closure', function (int $i) use ($cl): int { return $cl($i); });
$sumNul = 0;
for ($i = 0; $i < 2000; $i = $i + 1) {
    $sumNul = $sumNul + $nul($i);
}
echo 'closure ?array: sum=', $sumNul, "\n";
measure('arrow fn', function (int $i) use ($ar): int { return $ar($i); });
measure('call_user_func', function (int $i) use ($cl): int { return call_user_func($cl, $i); });
measure('call_user_func_array', function (int $i) use ($cl): int { return call_user_func_array($cl, [$i]); });
measure('__invoke', function (int $i) use ($inv): int { return $inv($i); });
measure('closure property', function (int $i) use ($hold): int { return $hold->run($i); });
measure('first-class fn', function (int $i) use ($fcc): int { return $fcc($i); });
measure('first-class static', function (int $i) use ($fst): int { return $fst($i); });
measure('first-class method', function (int $i) use ($fme): int { return $fme($i); });
measure('callable param', $cl);

echo $cl(1), ' ', $ar(1), ' ', $nul(1), ' ', $inv(1), ' ', $hold->run(1), ' ', $fcc(1), ' ', $fst(1), ' ', $fme(1), "\n";
echo $cl(1, 8), ' ', $ar(1, 9), ' ', $fst(1, 4), "\n";
$gs = '';
$cl(2, 0, $gs);
echo $gs, ' ';
$nul(3, $arr);
echo $arr[0], "\n";
echo "done
";
