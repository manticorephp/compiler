<?php
// An erased receiver's `$o->x` read and write: classes overloading __get/__set,
// a class declaring x, a dynamic bag and a plain class all reach one shared helper.
class M1 { public array $log = []; public function __get(string $n): string { return 'M1:' . $n; } public function __set(string $n, mixed $v): void { $this->log[] = "M1 $n=$v"; } }
class M2 { public array $log = []; public function __get(string $n): string { return 'M2:' . $n; } public function __set(string $n, mixed $v): void { $this->log[] = "M2 $n=$v"; } }
class M3 extends M2 { public function __get(string $n): string { return 'M3:' . $n; } }
class Fixed { public mixed $x = 'fixed'; public function __get(string $n): string { return 'never'; } }
#[\AllowDynamicProperties]
class Plain { public int $y = 1; }

/** @param list<object> $objs */
function run(array $objs): void {
    foreach ($objs as $o) {
        echo get_class($o), ' read=', var_export(isset($o->x) ? $o->x : ($o instanceof Plain ? 'unset' : $o->x), true), "\n";
        $o->x = 42;
        echo get_class($o), ' after=', var_export($o->x, true);
        if (property_exists($o, 'log')) { echo ' log=', json_encode($o->log); }
        echo "\n";
    }
}
$bag = new stdClass; $bag->x = 'bag';
run([new M1, new M2, new M3, new Fixed, $bag, new Plain]);
