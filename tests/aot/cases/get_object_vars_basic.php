<?php
class Point {
    public int $x;
    public string $label;
    public function __construct(int $x, string $label) {
        $this->x = $x;
        $this->label = $label;
    }
}
$p = new Point(7, "origin");
foreach (get_object_vars($p) as $k => $v) {
    echo $k, "=", $v, ";";
}
