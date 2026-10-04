<?php
// An array read out of a static property into a local is the local's own
// value: overwriting the static afterwards must not free it.
final class C {
    /** @var array<string,string> */
    public static array $map = [];
    /** @var list<string> */
    public static array $list = [];
    public static function swap(): string { self::$map = ['k' => str_repeat('s', 40)]; self::$list = []; return '|'; }
    public static function show(array $m, string $sep): string { return $m['k'] . $sep; }
}
C::$map = ['k' => str_repeat('v', 40)];
$m = C::$map;
C::$map = ['k' => str_repeat('w', 40)];
$junk = [str_repeat('x', 40), str_repeat('y', 40)];
echo $m['k'], "\n";
C::$list = [str_repeat('a', 40)];
$l = C::$list;
C::$list = [str_repeat('b', 40)];
$junk2 = [str_repeat('z', 40)];
echo $l[0], "\n";
echo C::show(C::$map, C::swap()), "\n";
C::$list = [str_repeat('c', 40), str_repeat('d', 40)];
foreach (C::$list as $s) { C::$list = []; $junk3 = [str_repeat('q', 40)]; echo $s, "\n"; }
