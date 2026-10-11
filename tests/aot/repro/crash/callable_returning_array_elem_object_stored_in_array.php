<?php
// A callable that returns an object read from an array element, stored into an array, traps (refcount underflow).
// issue: #173
final class E {
    public function __construct(private string $value) {}
    public function toString(): string { return $this->value; }
}
function run(array $elements, callable $get): void {
    $items = [];
    foreach ($elements as $i => $e) { $items[] = [$e, $i, $get($e)]; }
    echo count($items), "\n";
}
$inner = [['expression' => new E('string')], ['expression' => new E('int')]];
run($inner, static fn (array $v): E => $v['expression']);
echo $inner[0]['expression']->toString(), $inner[1]['expression']->toString(), "\n";
