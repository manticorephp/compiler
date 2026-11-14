<?php
// An object of an acyclic class is never buffered as a cycle root; an object of a cyclic class is (superset: gc_status()['roots'] is the live buffer).
final class Tag { public string $name = 't'; }
final class Point { public int $x = 0; public ?Tag $t; public function __construct() { $this->t = new Tag; } }
final class Link { public ?Link $next = null; }
if ($argc > 99) { gc_collect_cycles(); } // links the collector
gc_disable();

function share(array $objs): int {
    $before = gc_status()['roots'];
    $copy = $objs;
    foreach ($copy as $k => $o) { $copy[$k] = null; }
    return gc_status()['roots'] - $before;
}

$points = [];
for ($i = 0; $i < 50; $i++) { $points[] = new Point; }
$links = [];
for ($i = 0; $i < 50; $i++) { $links[] = new Link; }
echo 'acyclic: ', share($points) > 0 ? 'buffered' : 'not buffered', "\n";
echo 'cyclic: ', share($links) > 0 ? 'buffered' : 'not buffered', "\n";
