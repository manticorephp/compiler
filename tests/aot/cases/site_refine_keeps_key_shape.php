<?php
// A doc-typed array<string, bool[]> ctor param seen once as a vec (its string
// keys still untyped in an early round) kept the vec shape: the mask was read
// through int keys and a boxed false answered true.
final class P { public function __construct(public string $name, public bool $byRef, public bool $variadic = false) {} }
final class F {
    /** @param mixed[] $params */
    public function __construct(public string $name, public array $params) {}
}
final class MS {
    /** @var array<string, bool[]> */
    private array $refMasks;
    /** @param array<string, bool[]> $refMasks */
    public function __construct(array $refMasks) { $this->refMasks = $refMasks; }
    public function isRef(string $fn, int $p): bool {
        if (!isset($this->refMasks[$fn])) { return false; }
        $mask = $this->refMasks[$fn];
        return $p < \count($mask) ? $mask[$p] : false;
    }
}
/** @param F[] $fns */
function build(array $fns): MS {
    $refMasks = [];
    foreach ($fns as $fn) { $mask = []; foreach ($fn->params as $p) { $mask[] = $p->byRef; } $refMasks[$fn->name] = $mask; }
    return new MS($refMasks);
}
$ms = build([new F('cmpx', [new P('a', false), new P('b', false)]), new F('srt', [new P('arr', true)])]);
var_dump($ms->isRef('cmpx', 0), $ms->isRef('cmpx', 1), $ms->isRef('srt', 0));
