<?php
// #502 sibling: `$x = ($a[$k] = <computed>)` parks the value in a temp block typed at lowering.
final class Span { public function __construct(public int $a) {} }
final class Lit { public function __construct(public Span $span) {} }
final class Call {
    /** @param Lit[] $args */
    public function __construct(public array $args) {}
}
final class Idx {
    private static function fresh(Lit $n): Span { return new Span($n->span->a + 100); }
    public function add(string $k, Call $c, bool $low): void {
        $lit = $c->args[0];
        /** @var array<string, Span> $m */
        $m = [];
        $x = ($m[$k] = $low ? $lit->span : self::fresh($lit));
        $y = ($m[$k . '!'] = match ($low) { true => $lit->span, false => self::fresh($lit) });
        echo $k, ' ', $x->a, ' ', $y->a, ' ', \count($m), "\n";
    }
}
$ix = new Idx();
for ($i = 0; $i < 4; $i++) {
    $ix->add('k' . $i, new Call([new Lit(new Span($i)), $i > 100 ? "x" : $i]), $i % 2 === 0);
}
