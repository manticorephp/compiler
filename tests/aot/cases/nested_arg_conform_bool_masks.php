<?php
// Masks built from boxed property reads (cell receivers) in an erased local,
// handed to a ctor param doc-typed array<string, bool[]>: the inner buffers
// stayed CELL-hinted and the typed read of a boxed false answered true.
final class P { public function __construct(public string $name, public bool $byRef, public bool $variadic = false) {} }
final class F {
    /** @param P[] $params */
    public function __construct(public string $name, public array $params) {}
}
final class MS {
    /** @var array<string, bool[]> */
    private array $refMasks;
    /** @var array<string, bool> */
    private array $refVariadic;
    /** @param array<string, bool[]> $refMasks @param array<string, bool> $refVariadic */
    public function __construct(array $refMasks, array $refVariadic) { $this->refMasks = $refMasks; $this->refVariadic = $refVariadic; $d = $this->refMasks["cmpx"]; echo json_encode($d), " ", $d[0] ? "T" : "F", "\n"; $e = $this->refMasks["srt"]; echo json_encode($e), " ", $e[1] ? "T" : "F", "\n"; }
    public function isRef(string $fn, int $p): bool {
        if (!isset($this->refMasks[$fn])) { return false; }
        $mask = $this->refMasks[$fn];
        $cnt = \count($mask);
        $byRef = $p < $cnt ? $mask[$p] : false;
        return $byRef;
    }
}
final class Mod { /** @var F[] */ public array $functions = []; }
final class Flow {
    private ?MS $ms = null;
    public function run(Mod $module): MS {
        $refMasks = [];
        $refVariadic = [];
        foreach ($module->functions as $fn) {
            $mask = [];
            $tail = false;
            foreach ($fn->params as $p) { $mask[] = $p->byRef; $tail = $p->variadic && $p->byRef; }
            $refMasks[$fn->name] = $mask;
            $refVariadic[$fn->name] = $tail;
        }
        $this->ms = new MS($refMasks, $refVariadic);
        return $this->ms;
    }
}
function mk(string $n, bool $b): mixed { return new P($n, $b); }
$m = new Mod();
$m->functions[] = new F('cmpx', [new P('a', false), new P('b', false)]);
$f2 = new F('srt', [mk('arr', true), mk('k', false)]);
$m->functions[] = $f2;
$ms = (new Flow())->run($m);
var_dump($ms->isRef('cmpx', 0), $ms->isRef('cmpx', 1), $ms->isRef('srt', 0), $ms->isRef('srt', 1));
