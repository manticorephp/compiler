<?php
interface ESub { public function events(): array; public static function sevents(): array; }
class S1 implements ESub {
    public function events(): array { return ['r' => [['onR', 256]]]; }
    public static function sevents(): array { return ['r' => [['onR', 256]]]; }
}
class S2 implements ESub {
    public function events(): array { return ['x' => ['onX', -255]]; }
    public static function sevents(): array { return ['x' => ['onX', -255]]; }
}
function show(ESub $s): void {
    foreach ($s->events() as $ev => $params) {
        echo $ev, ' ', is_string($params[0]) ? 'str' : 'arr', "\n";
    }
    foreach ($s->sevents() as $ev => $params) {
        echo $ev, ' ', is_string($params[0]) ? 'str' : 'arr', "\n";
    }
}
show(new S1());
show(new S2());
