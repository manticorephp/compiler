<?php
// An array property passed by reference through an erased (mixed) receiver leaks the property's array on every call.
// issue: #138
final class D
{
    public static int $live = 0;
    public function __construct() { self::$live++; }
    public function __destruct() { self::$live--; }
}
final class Bucket
{
    /** @var D[] */
    public array $arr = [];
}
final class Sink
{
    /** @param D[] $out */
    public function f(array &$out): int { $out[] = new D(); return count($out); }
}
function feed(Sink $s, mixed $o): int { return $s->f($o->arr); }
$s = new Sink();
for ($i = 0; $i < 1000; $i++) {
    $c = new Bucket();
    $c->arr[] = new D();
    feed($s, $c);
}
$c = null;
echo D::$live === 0 ? "flat\n" : "leaks " . D::$live . "\n";
