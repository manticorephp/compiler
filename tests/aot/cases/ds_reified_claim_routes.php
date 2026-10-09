<?php
// A misfit argument on a bound receiver runs the origin body: by-ref args, omitted defaults, erased receivers and object claims all keep php's answers.
use Manticore\Ds\Map;

class A {}
class B extends A {}
final class Bucket { public array $items = ['p']; }

/** @template T */
final class Sink
{
    /** @param T $v */
    public function f(mixed $v, array &$out, int $d = 3): int
    {
        $out[] = get_debug_type($v) . ':' . $d;
        return count($out);
    }

    /** @param T $v */
    public function put(mixed $v = null): string { return get_debug_type($v); }

    /** @param T $v */
    public function args(mixed $v, mixed $w = 'dw'): string
    {
        return func_num_args() . ':' . implode('|', array_map('get_debug_type', func_get_args()));
    }
}

/** @template T */
final class Holder
{
    /** @param T $v */
    public function kind(mixed $v): string { return get_debug_type($v); }
}

function viaErasedMap(Map $m): void
{
    try { $m->set(2.5, 1); echo "stored\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    try { echo $m->get(5), "\n"; } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
    echo $m->get(5, 'dflt'), "\n";
    echo $m->get("5"), "\n";
    $m->set(6, 60);
    echo $m->get(6), "\n";
    $m[7] = 70;
    echo $m[7], ' ', count($m), "\n";
    unset($m[6]);
    var_dump(isset($m[6]), isset($m[7]), $m->has("5"), $m->has(5));
}

function viaErasedSink(Sink $s): void
{
    $out = ['x'];
    echo $s->f(5, $out), ' ', implode(',', $out), "\n";
    echo $s->f("s", $out, 9), ' ', implode(',', $out), "\n";
    echo $s->put(1.5), ' ', $s->put("t"), ' ', $s->put(), "\n";
    echo $s->args(5), ' ', $s->args(5, 6), ' ', $s->args("x"), ' ', $s->args("x", 2.5), "\n";
}

/** @var Sink<string> $s */
$s = new Sink();
$out = [];
echo $s->f(5, $out), ' ', implode(',', $out), "\n";
echo $s->f(2.5, $out, 7), ' ', implode(',', $out), "\n";
echo $s->f("ok", $out), ' ', implode(',', $out), "\n";
$bk = new Bucket();
echo $s->f(2.5, $bk->items), ' ', implode(',', $bk->items), "\n";
echo $s->f(true, $bk->items, 4), ' ', implode(',', $bk->items), "\n";
echo $s->put(), "\n";
echo $s->put(null), "\n";
echo $s->put(3), "\n";
echo $s->put("z"), "\n";
echo $s->args(5), ' ', $s->args(5, 6), ' ', $s->args("x"), ' ', $s->args("x", 2.5), "\n";
viaErasedSink($s);

/** @var Map<string,int> $m */
$m = new Map();
$m->set("5", 1);
viaErasedMap($m);
echo count($m), "\n";

/** @var Holder<A> $h */
$h = new Holder();
echo $h->kind(new B()), ' ', $h->kind(new A()), ' ', $h->kind("str"), ' ', $h->kind(null), "\n";
