<?php
// A cell-element local handed to typed string[] params of a function, a static method, a method and a constructor is converted at the call and the copy released.
/** @return string[] */
function pieces(string $g): array { return explode(' ', $g); }
function split(array $p, int $off): array
{
    foreach ($p as $k => $x) {
        if ($off === $k) { \array_splice($p, $k, 1, [$x . '1', $x . '2']); return $p; }
    }
    return $p;
}
final class J
{
    /** @param string[] $q */
    public static function s(array $q): string { return \implode('|', $q); }
    /** @param string[] $q */
    public function m(array $q): string { return \implode(',', $q); }
    /** @param string[] $q */
    public function __construct(public array $q = []) {}
}
function fix(string $g, int $cut): string
{
    $p = pieces($g);
    if ($cut >= 0) { $p = split($p, $cut); }
    $m = \count($p);
    for ($k = 0; $k < $m; $k++) { $p[$k] = '<' . $p[$k] . '>'; }
    $j = new J($p);
    return J::s($p) . $j->m($p) . \count($j->q);
}
echo fix('a b c', 1), "\n", fix('a b c', -1), "\n";
$s = 'a b c d e f g h';
for ($i = 0; $i < 2000; $i++) { fix($s, 1); fix($s, -1); }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { fix($s, 1); fix($s, -1); }
$d = memory_get_peak_usage() - $m0;
echo $d < 1000000 ? "flat" : "LEAK " . $d, "\n";
