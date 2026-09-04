<?php

// symfony/cache AbstractAdapter::commit, reduced. A closure with a by-ref
// parameter is built by `\Closure::bind` — which erases the concrete
// `__closure_N` to a bare `\Closure` — parked in a static property, and then
// invoked with an out-variable that was never assigned.
//
// Two things have to work at once: the undefined local must become a real
// initialized slot (php vivifies it as NULL), and the invoke must hand the
// callee that slot's ADDRESS even though no static mask names the callee. The
// mask is recovered at run time from the closure's fn pointer.

final class Store
{
    /** @var \Closure|null */
    private static $split = null;

    /** @var string[] */
    private array $items = [];

    public function add(string $k): void
    {
        $this->items[] = $k;
    }

    /** @return string[] */
    public function commit(): array
    {
        self::$split ??= \Closure::bind(
            static function (array $items, string $prefix, ?array &$expired): array {
                $expired = [];
                $keep = [];
                foreach ($items as $it) {
                    if (str_starts_with($it, $prefix)) {
                        $keep[] = $it;
                    } else {
                        $expired[] = $it;
                    }
                }
                return $keep;
            },
            null,
            self::class
        );

        $keep = (self::$split)($this->items, 'a', $dropped);
        if ($dropped) {
            echo 'dropped: ', implode(',', $dropped), "\n";
        }
        return $keep;
    }
}

$s = new Store();
$s->add('alpha');
$s->add('beta');
$s->add('anchor');
$s->add('gamma');
var_dump($s->commit());

// The same erasure without Closure::bind: a `\Closure`-typed local.
$fill = static function (int $n, ?array &$out): int {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $i * $i;
    }
    return $n;
};
/** @var \Closure $erased */
$erased = $fill;
echo $erased(4, $squares), "\n";
var_dump($squares);

// A by-VALUE argument through the very same dynamic call site must still be
// passed by value — the select has to pick the right operand per slot.
$mix = static function (int $a, ?array &$out, string $tag): string {
    $out = [$a];
    return $tag . ':' . $a;
};
/** @var \Closure $erasedMix */
$erasedMix = $mix;
echo $erasedMix(5, $got, 'tag'), "\n";
var_dump($got);
