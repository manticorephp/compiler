<?php
// ownflow verifier rejects a loop-carried local assigned from an untyped array element and from new
class M {
    public function __construct(public int $n = 0) {}

    public static function opt(array $cs)
    {
        $left = $cs[0];
        $merged = [];
        for ($i = 1; $i < \count($cs); $i++) {
            $right = $cs[$i];
            if ($left instanceof self && $right instanceof self) {
                $left = new M($left->n + $right->n);
            } else {
                $merged[] = $left;
                $left = $right;
            }
        }
        $merged[] = $left;
        return $merged;
    }

    public static function nested(array $cs, bool $b)
    {
        $left = $cs[0];
        $merged = [];
        for ($i = 1; $i < \count($cs); $i++) {
            $right = $cs[$i];
            if ($left instanceof self && $right instanceof self) {
                if ($b) { $left = new M($left->n + $right->n); }
            } else {
                $merged[] = $left;
                $left = $right;
            }
        }
        $merged[] = $left;
        return $merged;
    }

    public static function negated(array $cs, bool $b)
    {
        $x = $cs[0];
        if (!($x instanceof self)) { return 'not M'; }
        if ($b) { $x = new M(99); }
        $x = $x;
        return $x->n;
    }
}

function show(array $a): void
{
    foreach ($a as $v) { echo $v instanceof M ? 'M(' . $v->n . ')' : \var_export($v, true), ' '; }
    echo "\n";
}

$cs = [new M(1), new M(2), 'x', new M(3), new M(4), new M(5), 7];
show(M::opt($cs));
show(M::nested($cs, true));
show(M::nested($cs, false));
var_dump(M::negated([new M(5)], true), M::negated([new M(5)], false), M::negated([3], true));
