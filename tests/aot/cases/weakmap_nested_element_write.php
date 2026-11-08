<?php
class O {}
class Once {
    public function __construct(protected WeakMap $values) {}
    public function value(object $o, string $h, callable $c) {
        if (! isset($this->values[$o])) { $this->values[$o] = []; }
        if (array_key_exists($h, $this->values[$o])) { return $this->values[$o][$h]; }
        return $this->values[$o][$h] = call_user_func($c);
    }
}
$w = new Once(new WeakMap); $o = new O;
echo $w->value($o, 'a', fn() => 'A1'), $w->value($o, 'a', fn() => 'A2'), $w->value($o, 'b', fn() => 'B'), "\n";

// ArrayObject supports the same indirect modification
$ao = new ArrayObject(['x' => [1]]);
$ao['x'][] = 2;
$ao['y']['k'] = 'v';
echo json_encode($ao->getArrayCopy()), "\n";

// no growth, and independent per-object buckets
$m = new WeakMap; $keep = [];
for ($i = 0; $i < 3; $i++) { $keep[$i] = new O; $m[$keep[$i]] = []; $m[$keep[$i]]['n'] = $i; $m[$keep[$i]]['n'] += 10; }
echo json_encode(array_map(fn($o) => $m[$o], $keep)), "\n";
$base = memory_get_peak_usage();
$o2 = new O; $m[$o2] = [];
for ($i = 0; $i < 20000; $i++) { $m[$o2]['k'] = str_repeat('x', 8) . $i; }
echo (memory_get_peak_usage() - $base) < 1000000 ? "flat\n" : "leak\n";
