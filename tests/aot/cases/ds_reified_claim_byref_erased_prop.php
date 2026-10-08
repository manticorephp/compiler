<?php
// A routed reified call keeps a written by-ref arg that reads an array property through an erased receiver instead of releasing it as an omitted default.
final class D
{
    public static int $live = 0;
    public function __construct(public string $n) { self::$live++; }
    public function __destruct() { self::$live--; }
}

final class Bucket
{
    /** @var D[] */
    public array $arr = [];
}

/** @template T */
final class Sink
{
    /** @param T $v */
    public function f(mixed $v, array &$out): int
    {
        $out[] = new D(get_debug_type($v) . count($out));
        return count($out);
    }
}

function feed(mixed $o, mixed $v): int
{
    /** @var Sink<string> $s */
    $s = new Sink();
    return $s->f($v, $o->arr);
}

$b = new Bucket();
$b->arr[] = new D('seed');
echo feed($b, 5), ' ', feed($b, 2.5), ' ', feed($b, "ok"), "\n";
foreach ($b->arr as $d) { echo $d->n, ' '; }
echo "\n";
for ($i = 0; $i < 20000; $i++) {
    $c = new Bucket();
    $c->arr[] = new D('x');
    feed($c, $i);
    feed($c, 1.5);
    if (count($c->arr) !== 3) { echo "bad count\n"; }
}
$c = null;
$b = null;
