<?php
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Tag { public function __construct(public string $name = '') {} }

class K {
    public int $v = 7;
    public function make(): Closure { return function (int $a) { return $a; }; }
    public static function makeStatic(): Closure { return static function () { return 1; }; }
    public function arrow(): Closure { return fn(int $x = 3) => $x + $this->v; }
    public function m(#[Tag('mm')] string $s, int $n = 5): string { return $s . $n; }
    public static function sm(array $a, ?int $k = null): int { return count($a); }
}
function plain(int $a, #[Tag('pp')] string $b = 'dflt'): string { return $b . $a; }

function show(Closure $c): void {
    $r = new ReflectionFunction($c);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $s = $p->getName();
        if ($p->isDefaultValueAvailable()) { $s .= '=' . var_export($p->getDefaultValue(), true); }
        foreach ($p->getAttributes() as $at) { $s .= ' @' . $at->getName() . '(' . implode(',', $at->getArguments()) . ')'; }
        $parts[] = $s;
    }
    echo $r->getNumberOfParameters(), ' ', $r->getNumberOfRequiredParameters(), ' [', implode('; ', $parts), "]\n";
}

$k = new K;
show($k->make());
show($k->arrow());
show($k->m(...));
show(K::sm(...));
show(plain(...));
show(function (#[Tag('lit')] $q, $z = [1, 2]) {});

echo get_class((new ReflectionFunction($k->make()))->getClosureThis()), "\n";
echo get_class((new ReflectionFunction($k->arrow()))->getClosureThis()), "\n";
var_dump((new ReflectionFunction(K::makeStatic()))->getClosureThis());
var_dump((new ReflectionFunction(static fn() => 1))->getClosureThis());
echo (new ReflectionFunction($k->arrow()))->invoke(), "\n";
echo (new ReflectionFunction($k->m(...)))->invokeArgs(['a', 2]), "\n";
