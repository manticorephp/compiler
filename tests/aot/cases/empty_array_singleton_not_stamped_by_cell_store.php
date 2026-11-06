<?php
class Cache
{
    protected static $c = [];
    public static function put($k, $d, $v)
    {
        if (isset(static::$c[$k][$d])) { return static::$c[$k][$d]; }
        return static::$c[$k][$d] = $v;
    }
}
function units(string $u): array
{
    $out = [];
    for ($i = 0, $n = strlen($u); $i < $n; $i++) { $out[] = ord($u[$i]); }
    return $out;
}
function mark(string $u): array
{
    $out = [];
    foreach (units($u) as $w) { $out[] = $w < 0 ? 0xFFFFFFFF : $w; }
    return $out;
}
echo Cache::put('a', '_', 'x'), Cache::put('b', '_', 'y'), "\n";
foreach (['b', 'T', 'title'] as $s) { echo implode(',', mark($s)), "\n"; }
