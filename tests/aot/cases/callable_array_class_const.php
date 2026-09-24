<?php
// A callable array whose receiver names a class by `C::class`, `self::class` or
// `__CLASS__` is a STATIC callable (symfony polyfill-mbstring title case).
final class T
{
    public static function run($s) { return preg_replace_callback('/(\w)(\w*)/', [__CLASS__, 'tc'], $s); }
    public static function run2($s) { return preg_replace_callback('/(\w)(\w*)/', [self::class, 'tc2'], $s); }
    public static function run3($s) { return preg_replace_callback('/(\w)(\w*)/', 'T::tc', $s); }
    private static function tc(array $m) { return strtoupper($m[1]) . strtolower($m[2]); }
    private static function tc2(array $m): string { return strtoupper($m[1]) . strtolower($m[2]); }
}
var_dump(T::run('hello wORLD'), T::run2('hello wORLD'), T::run3('ab cd'));
function apply(callable $f, string $s): string { return $f($s); }
final class U
{
    public static function up(string $s): string { return strtoupper($s); }
    public static function cmp(int $a, int $b): int { return $b <=> $a; }
    public function low(string $s): string { return strtolower($s); }
    public function go(): string { return apply([$this, 'low'], 'ABC'); }
}
$a = [1, 3, 2]; usort($a, ['U', 'cmp']); echo implode(',', $a), "\n";
echo apply(['U', 'up'], 'abc'), "\n";
echo apply([U::class, 'up'], 'abc'), "\n";
echo (new U())->go(), "\n";
echo implode(',', array_map(['U', 'up'], ['x', 'y'])), "\n";
