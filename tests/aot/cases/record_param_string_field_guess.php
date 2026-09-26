<?php
// A bare-array param read by STRING keys is a record: one field used in a
// concatenation does not make every element a string (php-cs-fixer's
// OrderedClassElementsFixer sorts its elements through such a param).
final class F {
    /** @var array<string, int> */
    private array $tp = ['method_private_static' => 9, 'method_public' => 5];
    private function pos(array $e): int { return $this->tp[$e['type'] . '_' . $e['visibility'] . ($e['static'] ? '_static' : '')]; }
    public function a(array $x): int { return $this->pos($x); }
}
$el = ['type' => 'method', 'visibility' => 'private', 'static' => true, 'name' => 'helper', 'start' => 3];
echo (new F())->a($el), "\n";
final class U {
    public static function stableSort(array $elements, callable $getComparedValue, callable $compareValues): array
    {
        $sortItems = [];
        foreach ($elements as $index => $element) { $sortItems[] = [$element, $index, $getComparedValue($element)]; }
        usort($sortItems, static function ($a, $b) use ($compareValues): int {
            $comparison = $compareValues($a[2], $b[2]);
            if (0 !== $comparison) { return $comparison; }
            return $a[1] <=> $b[1];
        });
        return array_map(static fn (array $item) => $item[0], $sortItems);
    }
}
final class G {
    /** @var array<string, int> */
    private array $typePosition = ['constant_public' => 1, 'property_public' => 2, 'method_public' => 5, 'method_private_static' => 9];
    private function pos(array $e): int { return $this->typePosition[$e['type'] . '_' . $e['visibility'] . ($e['static'] ? '_static' : '')]; }
    public function sort(array $elements): array
    {
        return U::stableSort($elements,
            fn (array $element): array => ['element' => $element, 'position' => $this->pos($element)],
            fn (array $a, array $b): int => ($a['position'] === $b['position']) ? $a['element']['start'] <=> $b['element']['start'] : $a['position'] <=> $b['position']);
    }
}
$els = [
    ['type' => 'constant', 'visibility' => 'public', 'static' => false, 'name' => 'A', 'start' => 1],
    ['type' => 'property', 'visibility' => 'public', 'static' => false, 'name' => 'x', 'start' => 2],
    ['type' => 'method', 'visibility' => 'private', 'static' => true, 'name' => 'helper', 'start' => 3],
    ['type' => 'method', 'visibility' => 'public', 'static' => false, 'name' => 'count', 'start' => 4],
];
foreach ((new G())->sort($els) as $e) { echo $e['name'], ' '; }
echo "\n";
