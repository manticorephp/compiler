<?php
class Box {
    public int $n = 5;
    public string $label = "init";
    public float $f = 1.5;
}
$o = new Box();
foreach (["n", "label", "f"] as $p) { echo $o->$p, "\n"; }
$k = "n";     $o->$k = 42;    echo $o->n, "\n";
$k = "label"; $o->$k = "set"; echo $o->label, "\n";
$k = "f";     $o->$k = 3.25;  echo $o->f, "\n";
