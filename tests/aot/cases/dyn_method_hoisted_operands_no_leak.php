<?php
// A dynamic method call whose name is a concat and whose arguments are fresh objects / strings: what the call hoists is given back every call. @serial: a memory measurement.
class Item { public function __construct(public int $n = 0) {} }
class Sink {
    public int $sum = 0;
    public function addK0(mixed $v): static { $this->sum += is_object($v) ? $v->n : strlen((string)$v); return $this; }
}
function mk(int $i): Item { return new Item($i); }
function turn(mixed $s, string $key, int $i): void {
    $s->{'add' . $key}(mk($i));
    $s->{'add' . $key}(new Item($i));
    $s->{'add' . $key}('x' . $i);
}
$s = new Sink();
for ($i = 0; $i < 2000; $i++) { turn($s, 'K0', $i); }
$b = memory_get_usage();
for ($i = 0; $i < 60000; $i++) { turn($s, 'K0', $i); }
$d = memory_get_usage() - $b;
echo $s->sum, "\n";
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";
