<?php
// A cached map of A|B objects re-read through `??=` as a cell array co-owns its objects: they died at the first reader's scope exit (symfony Caster::castObject)
class Stub { public string $name; public function __construct(string $n) { $this->name = "stub " . $n; } public function __destruct() { echo "dtor ", $this->name, "\n"; } }
final class A extends Stub {}
final class B extends Stub {}
final class Cache {
    private const PARENT = ['c3' => 'c2', 'c2' => 'c1', 'c1' => ''];
    private static array $cache = [];
    public static function get(string $k): array
    {
        $props = self::$cache[$k] ??= self::build($k);
        $out = array_replace($props, [$k . 'x' => 1]);
        $n = 0;
        foreach ($out as $kk => $v) { if (!isset($props[$kk])) { $n++; } }
        return $out;
    }
    private static function build(string $k): array
    {
        $r = [];
        if ('' !== $p = self::PARENT[$k]) {
            $r += self::$cache[$p] ??= self::build($p);
        }
        foreach (['x', 'y'] as $i => $n) { $r[$k . $n] = $i ? new A($k . $n) : new B($k . $n); }
        return $r;
    }
}
for ($i = 0; $i < 3; $i++) {
    foreach (['c3', 'c2', 'c1'] as $k) { $r = Cache::get($k); echo implode(',', array_keys($r)), "\n"; }
}
foreach (['c3', 'c1'] as $k) { echo Cache::get($k)['c1y']->name, "\n"; }
