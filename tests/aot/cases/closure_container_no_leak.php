<?php

// A closure stored in a container is owned by the slot that holds it: the store
// takes a count and an overwrite, an unset or the container's death gives it
// back. An array element used to take the count and never give it back, so a
// closure capturing $this kept its whole object graph alive (a WebSocket
// Connection per onStop hook). A `callable` that is NOT a closure env — a
// function name, an [obj, 'method'] array — shares the slot type and must never
// be released as one. memory_get_usage() answers the peak RSS (ru_maxrss) here,
// which a leak can only raise: each leaking row below is 100+ MB over 100 000
// calls, the bound is 3 MB. Two rows are measured but not bounded: a static
// property and a `mixed` array element never give back what an overwrite /
// unset takes off them, for ANY value (docs/ROADMAP.md); they pin correctness.
// @serial: a memory measurement.

/** @param callable(int): int $body */
function measure(string $label, callable $body, bool $bounded = true): void
{
    $sum = 0;
    for ($i = 0; $i < 2000; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    $n = $bounded ? 100000 : 5000;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    $ok = !$bounded || $growth < 3 * 1024 * 1024;
    echo $label, ': sum=', $sum, ' ', $ok ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

final class Pad
{
    public string $s;
    public function __construct(int $i) { $this->s = str_repeat('p', 1000 + $i % 7); }
    public function hook(): \Closure { return function (): int { return strlen($this->s); }; }
    public function len(): int { return strlen($this->s); }
}

final class Reg
{
    private int $seq = 0;
    /** @var array<int, \Closure> */
    private array $h = [];
    /** @var array<string, callable> */
    private array $named = [];
    /** @var array<int, mixed> */
    private array $mixed = [];
    public ?\Closure $one = null;
    /** @var callable|null */
    public $any = null;
    public static ?\Closure $st = null;

    public function on(\Closure $f): int { $this->seq++; $this->h[$this->seq] = $f; return $this->seq; }
    public function off(int $id): void { unset($this->h[$id]); }
    public function put(int $k, \Closure $f): void { $this->h[$k] = $f; }
    public function call(int $k): int { return ($this->h[$k])(); }
    public function onNamed(string $k, callable $f): void { $this->named[$k] = $f; }
    public function offNamed(string $k): void { unset($this->named[$k]); }
    public function callNamed(string $k, string $arg): int { return ($this->named[$k])($arg); }
    public function onMixed(int $k, mixed $f): void { $this->mixed[$k] = $f; }
    public function offMixed(int $k): void { unset($this->mixed[$k]); }
    public function count(): int { return count($this->h) + count($this->named) + count($this->mixed); }
    /** @return array<int, \Closure> */
    public function all(): array { return $this->h; }
}

final class Holder
{
    /** @var array<int, \Closure> */
    public array $h = [];
}

final class SelfRef
{
    public string $s;
    public ?\Closure $cb = null;
    public function __construct() { $this->s = str_repeat('c', 64); $this->cb = function (): int { return strlen($this->s); }; }
}

function localArray(Pad $p): int
{
    /** @var array<int, \Closure> $a */
    $a = [];
    $a[] = $p->hook();
    $a[] = $p->hook();
    return $a[0]() + $a[1]();
}

function untypedLocal(Pad $p, int $i): int
{
    $a = [];
    $a[$i % 3] = $p->hook();
    $a[$i % 3] = $p->hook();
    return count($a);
}

$reg = new Reg();

measure('local only', function (int $i): int {
    $p = new Pad($i);
    $f = $p->hook();
    return $f();
});
measure('array<int,Closure> element unset', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $id = $reg->on($p->hook());
    $n = $reg->call($id);
    $reg->off($id);
    return $n;
});
measure('array<int,Closure> element overwrite', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $reg->put(-1 - $i % 4, $p->hook());
    return $reg->call(-1 - $i % 4);
});
measure('array<string,callable> element unset', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $reg->onNamed('k', function (string $x) use ($p): int { return strlen($x) + $p->len(); });
    $n = $reg->callNamed('k', 'ab');
    $reg->offNamed('k');
    return $n;
});
measure('array<int,mixed> element unset', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $reg->onMixed(7, $p->hook());
    $reg->offMixed(7);
    return 1;
}, false);
measure('?Closure property overwrite', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $reg->one = $p->hook();
    return ($reg->one)();
});
measure('callable property overwrite', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $reg->any = $p->hook();
    return ($reg->any)();
});
measure('static property overwrite', function (int $i): int {
    $p = new Pad($i);
    Reg::$st = $p->hook();
    return (Reg::$st)();
}, false);
measure('holder dies', function (int $i): int {
    $p = new Pad($i);
    $h = new Holder();
    $h->h[] = $p->hook();
    $h->h[] = $p->hook();
    return count($h->h);
});
measure('local array dies', function (int $i): int {
    return localArray(new Pad($i));
});
measure('untyped local array', function (int $i): int {
    return untypedLocal(new Pad($i), $i);
});
measure('copy then unset', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $id = $reg->on($p->hook());
    $copy = $reg->all();
    $reg->off($id);
    $n = $copy[$id]();
    return $n;
});
measure('read then unset', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $id = $reg->on($p->hook());
    $all = $reg->all();
    $f = $all[$id];
    unset($all);
    $reg->off($id);
    return $f();
});
measure('hook unregisters itself', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $id = 0;
    $id = $reg->on(function () use ($reg, &$id, $p): int { $reg->off($id); return $p->len(); });
    return $reg->call($id);
});
measure('foreach over hooks', function (int $i) use ($reg): int {
    $p = new Pad($i);
    $a = $reg->on($p->hook());
    $b = $reg->on(function () use ($p): int { return 1 + $p->len(); });
    $n = 0;
    foreach ($reg->all() as $k => $f) {
        if ($k > 0) { $n += $f(); $reg->off($k); }
    }
    return $n + $a - $b;
});
measure('sorted hooks', function (int $i): int {
    $p = new Pad($i);
    $q = new Pad($i + 3);
    /** @var array<int, \Closure> $hs */
    $hs = [$p->hook(), $q->hook(), function () use ($p): int { return 7; }];
    usort($hs, fn (\Closure $x, \Closure $y): int => $x() <=> $y());
    $v = array_values($hs);
    return $hs[0]() + $v[2]();
});

// Every array builder over a `Closure[]`, its source unset before the result
// is called: a builder copies closure words out of a buffer that owns them, so
// the copy must take its own count. Rows marked unbounded still keep their
// closures (the builder's result does not record that it owns them); they pin
// correctness.
function mkc(int $v): \Closure { return (new Pad($v))->hook(); }
/** @return array<int, \Closure> */
function srcc(int $i): array { /** @var array<int, \Closure> $a */ $a = []; $a[] = mkc($i); $a[] = mkc($i + 1); $a[] = mkc($i + 2); return $a; }
function b_values(int $i): int { $a = srcc($i); $z = array_values($a); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder values', fn (int $i): int => b_values($i % 5));
function b_merge(int $i): int { $a = srcc($i); $z = array_merge($a, [mkc(4)]); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder merge', fn (int $i): int => b_merge($i % 5));
function b_slice(int $i): int { $a = srcc($i); $z = array_slice($a, 1); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder slice', fn (int $i): int => b_slice($i % 5));
function b_reverse(int $i): int { $a = srcc($i); $z = array_reverse($a); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder reverse', fn (int $i): int => b_reverse($i % 5));
function b_filter(int $i): int { $a = srcc($i); $z = array_filter($a, fn ($f) => $f() > 1); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder filter', fn (int $i): int => b_filter($i % 5));
function b_map(int $i): int { $a = srcc($i); $z = array_map(fn ($f) => $f, $a); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder map', fn (int $i): int => b_map($i % 5));
function b_combine(int $i): int { $a = srcc($i); $z = array_combine(['a', 'b', 'c'], $a); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder combine', fn (int $i): int => b_combine($i % 5));
function b_chunk(int $i): int { $a = srcc($i); $z = array_chunk($a, 2)[0]; unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder chunk', fn (int $i): int => b_chunk($i % 5));
function b_unique(int $i): int { $a = srcc($i); $z = array_unique($a, SORT_REGULAR); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder unique', fn (int $i): int => b_unique($i % 5));
function b_diff_key(int $i): int { $a = srcc($i); $z = array_diff_key($a, [0 => 1]); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder diff_key', fn (int $i): int => b_diff_key($i % 5));
function b_intersect_key(int $i): int { $a = srcc($i); $z = array_intersect_key($a, [1 => 1, 2 => 1]); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder intersect_key', fn (int $i): int => b_intersect_key($i % 5));
function b_copy(int $i): int { $a = srcc($i); $z = $a; unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder copy', fn (int $i): int => b_copy($i % 5));
function b_splice(int $i): int { $a = srcc($i); $z = array_splice($a, 1, 1); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder splice', fn (int $i): int => b_splice($i % 5), false);
function b_pad(int $i): int { $a = srcc($i); $z = array_pad($a, 5, mkc(7)); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder pad', fn (int $i): int => b_pad($i % 5), false);
function b_fill(int $i): int { $a = srcc($i); $z = array_fill(0, 3, mkc(5)); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder fill', fn (int $i): int => b_fill($i % 5), false);
function b_replace(int $i): int { $a = srcc($i); $z = array_replace($a, [1 => mkc(8)]); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder replace', fn (int $i): int => b_replace($i % 5), false);
function b_spread(int $i): int { $a = srcc($i); $z = [...$a, mkc(6)]; unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder spread', fn (int $i): int => b_spread($i % 5), false);
function b_union(int $i): int { $a = srcc($i); $z = $a + [5 => mkc(6)]; unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder union', fn (int $i): int => b_union($i % 5), false);
function b_iterator_to_array(int $i): int { $a = srcc($i); $z = iterator_to_array(new ArrayIterator($a)); unset($a); $n = 0; foreach ($z as $f) { $n += $f(); } return $n; }
measure('builder iterator_to_array', fn (int $i): int => b_iterator_to_array($i % 5), false);

/** @var array<string, \Closure> $ss */
$ss = ['x' => mkc(1), 'y' => mkc(2)];
$mr = array_merge_recursive($ss, ['z' => mkc(4)]);
['x' => $lx] = $ss;
[$l0, $l1] = srcc(1);
$cf = mkc(3);
$cm = compact('cf');
$col = array_column([['f' => mkc(1)], ['f' => mkc(2)]], 'f');
unset($ss, $cf);
echo 'merge_recursive ', $mr['z'](), ' list ', $lx(), $l0(), $l1(), ' compact ', $cm['cf'](), ' column ', $col[1](), "\n";

echo 'left: ', $reg->count(), "\n";

// A closure capturing $this stored in its own object is a cycle: php frees it
// only through its cycle collector, which Manticore does not have. Correctness
// only — no growth bound.
$sr = new SelfRef();
echo 'self: ', ($sr->cb)(), "\n";

// Every callable kind through one array<string,callable> slot: stored,
// overwritten, unset — none of them may be released as a closure env. The
// closures are called; a name / an [obj, 'm'] pair is only read back (calling
// one straight out of a slot is a separate gap, docs/ROADMAP.md).
final class Tool
{
    public function __construct(private string $tag) {}
    public function up(string $s): string { return $this->tag . strtoupper($s); }
    public static function rev(string $s): string { return strrev($s); }
}

/** @var array<string, callable> $cbs */
$cbs = [];
$tool = new Tool('t:');
$suffix = '!';
for ($i = 0; $i < 20000; $i++) {
    $cbs['str'] = 'strtoupper';
    $cbs['static'] = 'Tool::rev';
    $cbs['arr'] = [$tool, 'up'];
    $cbs['sarr'] = ['Tool', 'rev'];
    $cbs['fcc'] = strrev(...);
    $cbs['mfcc'] = $tool->up(...);
    $cbs['clo'] = function (string $s) use ($suffix): string { return $s . $suffix; };
    $cbs['arrow'] = fn (string $s): string => $s . $suffix . $suffix;
    if ($i % 2 === 0) {
        unset($cbs['str'], $cbs['arr'], $cbs['fcc'], $cbs['clo']);
    }
}
foreach (array_keys($cbs) as $k) {
    $f = $cbs[$k];
    if (is_string($f)) {
        echo $k, ' => ', $f, "\n";
    } elseif (is_array($f)) {
        echo $k, ' => ', \count($f), " parts\n";
    } else {
        $r = $f('ab');
        echo $k, ' => ', $r, "\n";
    }
}
$cbs['clo'] = 'strrev';
$cbs['str'] = function (string $s): string { return '[' . $s . ']'; };
$cbs['arr'] = strlen(...);
echo $cbs['clo'], ' ', $cbs['str']('q'), ' ', $cbs['arr']('four'), "\n";
unset($cbs);
echo "done\n";
