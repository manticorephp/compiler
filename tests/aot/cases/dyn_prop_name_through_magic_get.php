<?php
class Base { public int $y = 1; }
class Mag extends Base {
    private array $store = ['year' => 2026, 'month' => 10];
    public function __get($n) { return $this->store[$n] ?? null; }
    public function unit($unit, $value) {
        return array_map(function ($name) use ($unit, $value) {
            return (int) ($name === $unit ? $value : $this->$name);
        }, ['year', 'month']);
    }
}
$m = new Mag;
echo json_encode($m->unit('month', 5)), "\n";
$n = 'year'; echo $m->$n, "\n";
