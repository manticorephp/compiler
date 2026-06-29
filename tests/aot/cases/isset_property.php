<?php
class Box {
    public ?string $name = "n";
}
$b = new Box();
echo isset($b->name) ? "1" : "0";
$b->name = null;
echo ",", isset($b->name) ? "1" : "0";
