<?php
// #502: `$a[$k] ??= $c ? $n->prop : f($n)` once a later inference run erases $n to a cell.
// The `??=` value block kept the type it adopted on the first InferTypes run (obj) while its
// last node became a cell, so the arm boxed the cell word as an object pointer.
final class Span { public function __construct(public int $a) {} }
final class Lit { public function __construct(public Span $span) {} }
final class Call {
    /** @param Lit[] $args */
    public function __construct(public array $args) {}
}
final class Idx {
    /** @var array<string, Span> */
    private array $m = [];
    private static function fresh(Lit $n): Span { return new Span($n->span->a + 100); }
    public function add(string $k, Call $c, bool $low): void {
        $lit = $c->args[0];
        $this->m[$k] ??= $low ? $lit->span : self::fresh($lit);
        $x = ($this->m[$k . '!'] ??= match ($low) { true => $lit->span, false => self::fresh($lit) });
        echo $k, ' ', $x->a, "\n";
    }
    public function dump(): void { foreach ($this->m as $k => $s) { echo $k, ' ', $s->a, "\n"; } }
}
$ix = new Idx();
for ($i = 0; $i < 6; $i++) {
    // The second element is not a Lit: the property's element type is erased only once the
    // call sites are re-inferred.
    $ix->add('k' . ($i % 4), new Call([new Lit(new Span($i)), $i > 100 ? "x" : $i]), $i % 2 === 0);
}
$ix->dump();
