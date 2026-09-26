<?php
final class Opt {
    private ?array $av;
    private ?\Closure $norm;
    public function __construct(?array $allowedValues = null, ?\Closure $normalizer = null) {
        if (null !== $allowedValues) {
            $allowedValues = array_map(
                fn ($a) => $a instanceof \Closure ? $this->unbind($a) : $a,
                $allowedValues,
            );
        }
        $this->av = $allowedValues;
        $this->norm = $normalizer !== null ? $this->unbind($normalizer) : null;
    }
    private function unbind(\Closure $c): \Closure { return $c->bindTo(null); }
    public function ok($v): bool { foreach ($this->av ?? [] as $a) { if ($a instanceof \Closure && !$a($v)) { return false; } } return true; }
    public function norm($v) { return ($this->norm)($v); }
}
function defs(): array {
    $asserts = [static function (array $values): bool {
        foreach ($values as $value) { if ('' === $value) { return false; } }
        return true;
    }];
    $n = static function (array $v): array { return array_map('strtolower', $v); };
    return [new Opt($asserts, $n), new Opt($asserts, $n), new Opt(['a', 'b']), new Opt($asserts)];
}
for ($i = 0; $i < 3; $i++) {
    foreach (defs() as $o) { var_dump($o->ok(['x']), $o->ok([''])); }
    echo implode(',', defs()[0]->norm(['A', 'B'])), "\n";
}
echo "done\n";
$f = static function (array $values): bool { return !in_array('', $values, true); };
$g = $f->bindTo(null);
var_dump($g instanceof \Closure, $g(['']), $g(['x']));
$m = array_map(fn ($a) => $a instanceof \Closure ? $a->bindTo(null) : $a, [$f, 'q']);
var_dump($m[0] instanceof \Closure, $m[0](['']), $m[1]);
