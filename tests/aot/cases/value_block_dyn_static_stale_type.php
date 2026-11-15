<?php
// #502 sibling: `Cls::$name(args)` and `Cls::$$name` chains are value blocks typed from a captured arm.
final class Span { public function __construct(public int $a) {} }
final class Lit { public function __construct(public Span $span) {} }
final class Call {
    /** @param Lit[] $args */
    public function __construct(public array $args) {}
}
final class K {
    public static Span $sp;
    public static function one(Span $s): Span { return new Span($s->a + 1); }
    public static function two(Span $s): Span { return new Span($s->a + 2); }
    public static function run(Call $c, string $m): void {
        $lit = $c->args[0];
        $r = self::$m($lit->span);
        echo $m, ' ', $r->a, "\n";
        self::$sp = $r;
        $n = 'sp';
        echo self::$$n->a, "\n";
    }
}
for ($i = 0; $i < 4; $i++) {
    K::run(new Call([new Lit(new Span($i)), $i > 100 ? "x" : $i]), $i % 2 ? 'one' : 'two');
}
