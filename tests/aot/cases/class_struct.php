<?php
use Manticore\Attr\Struct;
#[Struct]
final class Vec2 {
    public function __construct(public int $x, public int $y) {}
}
$v = new Vec2(10, 32);
echo $v->x + $v->y;
