<?php

// A read off a FRESH base whose result is not a scalar — `f()->data`,
// `(new M())->name`, `$c->receive()->data`, `rows()[0]`, `objs()[0]->data` —
// left the base with no owner: the emitter drops the base of a SCALAR read, but
// a string / array / object read hands out a value borrowed from the base, so
// it could not, and nothing else did. One whole object or array per read. The
// base now gets a hidden owning local (SpillFreshBases), released on the next
// evaluation or at scope exit. The destructor lines pin that such a temporary
// is now destroyed at all (it never was), and in php's order when the read is
// the frame's last use of it.
// memory_get_usage() answers the peak RSS (ru_maxrss) here, which a leak can
// only raise; the bound is 3 MB over 100 000 calls. @serial: a memory
// measurement.

/** @param callable(int): int $body */
function measure(string $label, int $n, callable $body): void
{
    $sum = 0;
    for ($i = 0; $i < 200; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < $n; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    echo $label, ': sum=', $sum, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

final class M
{
    /** @var array<int,int> */
    public array $list;
    public function __construct(public string $data) { $this->list = [1, 2, strlen($data)]; }
}

final class Src
{
    public function make(int $i): M { return new M(str_repeat('m', 40 + $i % 5)); }
    public function maybe(int $i): ?M { return new M(str_repeat('n', 40 + $i % 5)); }
    public static function build(int $i): M { return new M(str_repeat('s', 40 + $i % 5)); }
    public function self(): Src { return $this; }
    public M $held;
    public function __construct() { $this->held = new M('held'); }
}

function mk(int $i): M { return new M(str_repeat('f', 40 + $i % 5)); }
/** @return array<int,string> */
function rows(int $i): array { return [str_repeat('r', 40 + $i % 5), 'b']; }
/** @return array<int,M> */
function objs(int $i): array { return [new M(str_repeat('o', 40 + $i % 5))]; }
/** @return array<string,array<int,int>> */
function nested(int $i): array { return ['k' => [$i, $i + 1, $i + 2]]; }

$src = new Src();
measure('function result ->string', 100000, fn (int $i): int => strlen(mk($i)->data));
measure('method result ->string', 100000, fn (int $i): int => strlen($src->make($i)->data));
measure('nullable method result ->string', 100000, fn (int $i): int => strlen($src->maybe($i)->data));
measure('static result ->string', 100000, fn (int $i): int => strlen(Src::build($i)->data));
measure('new ->string', 100000, fn (int $i): int => strlen((new M(str_repeat('w', 40 + $i % 5)))->data));
measure('result ->array', 100000, fn (int $i): int => count(mk($i)->list));
measure('borrowed result ->object->string', 100000, fn (int $i): int => strlen($src->self()->held->data));
measure('result [0] string', 100000, fn (int $i): int => strlen(rows($i)[0]));
measure('result [0]->string', 100000, fn (int $i): int => strlen(objs($i)[0]->data));
measure('result [k] array', 100000, fn (int $i): int => count(nested($i)['k']));

final class D
{
    public function __construct(public string $name) {}
    public function __destruct() { echo 'destruct ', $this->name, "\n"; }
}

function nameOf(string $n): string
{
    return (new D($n))->name;
}

echo 'got ', nameOf('temp'), "\n";
echo 'held ', $src->self()->held->data, "\n";
echo "done\n";
