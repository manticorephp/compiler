<?php
class Counter {
    public function tick(): int {
        static $n = 0;
        $n = $n + 1;
        return $n;
    }
}
$a = new Counter();
$b = new Counter();
echo $a->tick(), ",", $b->tick(), ",", $a->tick();
