<?php
class Box {
    public int $count = 7;
    public string $tag = "hi";
}
$b = new Box();
echo $b->count, "/", $b->tag;
