<?php
// A generator frame gives back everything it holds however it ends: abandoned
// before the first resume, abandoned at a yield, finished, or left by an
// exception. @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('q', 512) . $i; } }
function g(Big $p, int $i): \Generator
{
    $a = new Big($i);
    yield 'k' . $i => $a;
    $b = new Big($i + 1);
    $x = yield $b;
    if ($i % 4 === 3) { throw new \RuntimeException('x' . $i); }
    yield $p;
    return new Big($i + 2);
}
function turn(int $i): int
{
    $n = 0;
    $g = g(new Big($i), $i);
    $m = $i % 4;
    if ($m === 0) { return 0; }
    $n += strlen($g->current()->s);
    if ($m === 1) { return $n; }
    try {
        foreach ($g as $k => $v) { $n += strlen($v->s) + (\is_string($k) ? 1 : 2); $g->send(new Big($i)); }
        $n += strlen($g->getReturn()->s);
    } catch (\RuntimeException $e) {
        $n += strlen($e->getMessage());
    }
    return $n;
}
$t = 0;
for ($i = 0; $i < 2000; $i++) { $t += turn($i); }
$b = memory_get_usage();
for ($i = 0; $i < 40000; $i++) { $t += turn($i); }
$d = memory_get_usage() - $b;
echo $t > 0 ? 'ran' : 'idle', "\n";
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";
