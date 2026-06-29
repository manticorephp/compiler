<?php
class Box {
    public int $w;
    public int $h;
    public function __construct(int $w, int $h) {
        $this->w = $w;
        $this->h = $h;
    }
    public function area(): int {
        return $this->w * $this->h;
    }
}
$a = new Box(3, 4);
$b = new Box(5, 6);
echo $a->area(), ",", $b->area();
