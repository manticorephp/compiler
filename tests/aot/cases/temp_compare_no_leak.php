<?php
// A value a call / new / clone hands back and a comparison, instanceof, a
// truthiness test or a type predicate reads to a scalar is released once read:
// `while ($c->receive() !== null)` kept every Message (1M calls = 291 MB).
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the smallest leak below is ~4 MB, the bound 2 MB. The
// destructor rows pin WHEN the temporary dies: before the chosen block runs.
// @serial: a memory measurement.
final class M { public function __construct(public string $d) {} }
final class S {
    public function obj(int $i): M { return new M(str_repeat('x', 200) . $i); }
    public function nobj(int $i): ?M { return $i < 0 ? null : new M(str_repeat('x', 200) . $i); }
    public function str(int $i): string { return str_repeat('x', 200) . $i; }
    public function nstr(int $i): ?string { return $i < 0 ? null : str_repeat('x', 200) . $i; }
    /** @return array<int,string> */
    public function arr(int $i): array { return [str_repeat('x', 200) . $i, 'b']; }
    public function clo(int $i): \Closure { $s = str_repeat('x', 200) . $i; return function () use ($s): int { return strlen($s); }; }
}
function fobj(int $i): M { return new M(str_repeat('x', 200) . $i); }
function fnobj(int $i): ?M { return $i < 0 ? null : new M(str_repeat('x', 200) . $i); }
/** @param callable(int): int $body */
function measure(string $label, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 2000; $i = $i + 1) { $sum = $sum + $body($i); }
    $before = memory_get_usage();
    for ($i = 0; $i < 50000; $i = $i + 1) { $sum = $sum + $body($i); }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 2 * 1024 * 1024 ? 'ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}
$s = new S();
measure('obj !== null', function (int $i) use ($s): int { return $s->obj($i) !== null ? 1 : 0; });
measure('nobj !== null', function (int $i) use ($s): int { return $s->nobj($i) !== null ? 1 : 0; });
measure('nobj === null', function (int $i) use ($s): int { return $s->nobj($i) === null ? 1 : 0; });
measure('fnobj != null', function (int $i): int { return fnobj($i) != null ? 1 : 0; });
measure('fobj == fobj', function (int $i): int { return fobj($i) == fobj($i + 1) ? 1 : 0; });
measure('fobj === fobj', function (int $i): int { return fobj($i) === fobj($i) ? 1 : 0; });
measure('obj instanceof', function (int $i) use ($s): int { return $s->obj($i) instanceof M ? 1 : 0; });
measure('nobj instanceof', function (int $i) use ($s): int { return $s->nobj($i) instanceof M ? 1 : 0; });
measure('!nobj', function (int $i) use ($s): int { return !$s->nobj($i) ? 1 : 0; });
measure('if (nobj)', function (int $i) use ($s): int { if ($s->nobj($i)) { return 1; } return 0; });
measure('if (obj)', function (int $i) use ($s): int { if ($s->obj($i)) { return 1; } return 0; });
measure('is_object', function (int $i) use ($s): int { return is_object($s->nobj($i)) ? 1 : 0; });
measure('is_null', function (int $i) use ($s): int { return is_null($s->nobj($i)) ? 1 : 0; });
measure('match true', function (int $i) use ($s): int { return match (true) { $s->nobj($i) !== null => 1, default => 0 }; });
measure('while !== null', function (int $i) use ($s): int { $k = 0; while ($k < 2 && $s->nobj($i) !== null) { $k++; } return $k; });
measure('stmt obj', function (int $i) use ($s): int { $s->obj($i); return 1; });
measure('stmt fobj', function (int $i): int { fobj($i); return 1; });
measure('str !== null', function (int $i) use ($s): int { return $s->nstr($i) !== null ? 1 : 0; });
measure('str === lit', function (int $i) use ($s): int { return $s->str($i) === 'a' ? 1 : 0; });
measure('str == lit', function (int $i) use ($s): int { return $s->str($i) == 'a' ? 1 : 0; });
measure('str <=> lit', function (int $i) use ($s): int { return $s->str($i) <=> 'a'; });
measure('!str', function (int $i) use ($s): int { return !$s->str($i) ? 1 : 0; });
measure('if (str)', function (int $i) use ($s): int { if ($s->str($i)) { return 1; } return 0; });
measure('stmt str', function (int $i) use ($s): int { $s->str($i); return 1; });
measure('arr === []', function (int $i) use ($s): int { return $s->arr($i) === [] ? 1 : 0; });
measure('arr == []', function (int $i) use ($s): int { return $s->arr($i) == [] ? 1 : 0; });
measure('!arr', function (int $i) use ($s): int { return !$s->arr($i) ? 1 : 0; });
measure('if (arr)', function (int $i) use ($s): int { if ($s->arr($i)) { return 1; } return 0; });
measure('stmt arr', function (int $i) use ($s): int { $s->arr($i); return 1; });
measure('clo !== null', function (int $i) use ($s): int { return $s->clo($i) !== null ? 1 : 0; });
measure('stmt clo', function (int $i) use ($s): int { $s->clo($i); return 1; });
measure('nobj ?: ', function (int $i) use ($s): int { return ($s->nobj($i) ?: 5) === 5 ? 0 : 1; });
measure('nobj ?? ', function (int $i) use ($s): int { return ($s->nobj($i) ?? 5) === 5 ? 0 : 1; });
measure('switch nstr', function (int $i) use ($s): int { switch ($s->nstr($i)) { case 'a': return 2; default: return 1; } });
measure('new !== null', function (int $i): int { return new M('x' . $i) !== null ? 1 : 0; });
measure('clone instanceof', function (int $i): int { $m = new M('x'); return (clone $m) instanceof M ? 1 : 0; });
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo 'destruct ', $this->n, "\n"; }
}
function mkd(string $n): ?D { return $n === '' ? null : new D($n); }
if (mkd('if') !== null) { echo "if body\n"; }
if (mkd('else') === null) { echo "never\n"; } else { echo "else body\n"; }
$k = 0;
while ($k < 2 && mkd('while' . $k) instanceof D) { echo 'while body ', $k, "\n"; $k++; }
$b = mkd('stmt') !== null;
echo 'after stmt ', $b ? 'y' : 'n', "\n";
function held(): int { $cur = Fiber::getCurrent(); return $cur === null ? 0 : 1; }
$f = new Fiber(function (): void {
    $n = 0;
    for ($i = 0; $i < 3; $i++) { if (Fiber::getCurrent() !== null) { $n++; } $n += held(); Fiber::suspend($i); }
    echo 'fiber current ', $n, "\n";
});
$f->start();
while (!$f->isTerminated()) { $f->resume(); }
echo 'outside current ', Fiber::getCurrent() === null ? 'null' : 'set', "\n";
echo "done\n";
