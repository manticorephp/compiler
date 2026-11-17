<?php
// A dynamic property read through a parent-typed receiver misses the subclass's declared property.
abstract class Shape {}
final class Sq extends Shape { public int $w = 3; }
final class Re extends Shape { public int $w = 2; }
$poly = [new Sq(), new Re()];
$p = 'w';
$s = 0;
foreach ($poly as $o) { $s += $o->$p; }
echo $s, "\n";
