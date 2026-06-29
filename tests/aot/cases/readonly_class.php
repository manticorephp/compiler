<?php
readonly class Coord {
    public function __construct(public float $lat, public float $lon) {}
}
$c = new Coord(50.45, 30.52);
echo $c->lat, ",", $c->lon;
