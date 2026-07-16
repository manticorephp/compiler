<?php
class Calc {
    public function __construct(private int $base) {}
    public function add(int $x): int { return $this->base + $x; }
    public function label(): string { return "calc:" . $this->base; }
    public function half(): float { return $this->base / 2.0; }
}
$o = new Calc(10);
$m = "add";   echo $o->$m(5), "\n";
$m = "label"; echo $o->$m(), "\n";
$m = "half";  echo $o->$m(), "\n";
foreach (["add","label"] as $mm) {
    echo ($mm === "add") ? $o->$mm(1) . "\n" : $o->$mm() . "\n";
}
