<?php

// A generator's frame OWNS the value it yields: `current` is dropped at the
// next yield and when the generator finishes, and every reader takes its own
// count — a foreach loop variable, `current()`, an IteratorAggregate's
// protocol loop. The slot used to be a borrow nobody released, so each value a
// foreach consumed leaked (a WebSocket server's `foreach ($ws as $m)` lost
// every message), and a generator body's own locals were never released
// before an overwrite. memory_get_usage() answers the peak RSS (ru_maxrss)
// here, which a leak can only raise; the bound is 3 MB over 100 000 values.
// @serial: a memory measurement.

final class Msg
{
    public function __construct(public readonly string $data) {}
}

function strings(int $n): \Generator
{
    for ($i = 0; $i < $n; $i++) { yield 'x' . $i; }
}

function objects(int $n): \Generator
{
    for ($i = 0; $i < $n; $i++) { yield new Msg('o' . $i); }
}

function viaLocal(int $n): \Generator
{
    for ($i = 0; $i < $n; $i++) {
        $m = new Msg('l' . $i);
        yield $m;
    }
}

/** @return \Generator<int, Msg> */
function typed(int $n): \Generator
{
    for ($i = 0; $i < $n; $i++) { yield new Msg('t' . $i); }
}

/** @implements \IteratorAggregate<int, Msg> */
final class Feed implements \IteratorAggregate
{
    public function __construct(private int $n) {}

    public function getIterator(): \Generator
    {
        $i = 0;
        while ($i < $this->n) {
            $m = new Msg('f' . $i);
            $i++;
            yield $m;
        }
    }
}

function measure(string $label, callable $run): void
{
    $run(2000);
    $before = memory_get_usage();
    $sum = $run(100000);
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

measure('strings', function (int $n): int {
    $t = 0;
    foreach (strings($n) as $v) { $t += strlen($v); }
    return $t;
});
measure('objects', function (int $n): int {
    $t = 0;
    foreach (objects($n) as $v) { $t += strlen($v->data); }
    return $t;
});
measure('yielded local', function (int $n): int {
    $t = 0;
    foreach (viaLocal($n) as $v) { $t += strlen($v->data); }
    return $t;
});
measure('typed', function (int $n): int {
    $t = 0;
    foreach (typed($n) as $v) { $t += strlen($v->data); }
    return $t;
});
measure('aggregate', function (int $n): int {
    $t = 0;
    foreach (new Feed($n) as $v) { $t += strlen($v->data); }
    return $t;
});
measure('current', function (int $n): int {
    $t = 0;
    $g = objects($n);
    while ($g->valid()) {
        $v = $g->current();
        $t += strlen($v->data);
        $g->next();
    }
    return $t;
});

function firstAndLast(): string
{
    $first = null;
    foreach (objects(5) as $v) {
        if ($first === null) { $first = $v; }
    }
    return $first->data . '/' . $v->data;
}

function previous(): string
{
    $prev = '';
    $out = '';
    foreach (strings(4) as $v) {
        $out .= $prev . ',';
        $prev = $v;
    }
    return $out . $prev;
}

function manual(): string
{
    $g = objects(3);
    $a = $g->current();
    $g->next();
    $b = $g->current();
    $g->next();
    $g->next();
    return $a->data . $b->data . ' ' . var_export($g->current(), true);
}

function keyed(): \Generator
{
    yield 'a' . 1 => new Msg('k1');
    yield 'b' => 'v2';
}

function echoes(): \Generator
{
    $x = yield 'first';
    while (true) { $x = yield 'got:' . $x; }
}

echo firstAndLast(), "\n", previous(), "\n", manual(), "\n";
foreach (keyed() as $k => $v) { echo $k, '=', is_string($v) ? $v : $v->data, ';'; }
echo "\n", implode(',', iterator_to_array(strings(3))), "\n";
$e = echoes();
echo $e->current(), ' ', $e->send('A'), ' ', $e->send('B' . 1), "\n";
echo "done\n";
