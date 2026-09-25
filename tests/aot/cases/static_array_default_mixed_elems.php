<?php
// A static array property's literal default with elements of different kinds
// reaches every erased reader (foreach, array_merge, a mixed param) with its
// types intact — shape-typed, bare-array and homogeneous alike.

/** @param array<string, mixed> $a */
function show(array $a): void
{
    foreach ($a as $k => $v) { echo $k, '=', var_export($v, true), ' '; }
    echo "\n";
}

final class Opts
{
    /** @var array{addLineNumbers: bool, contextLines: int, fromFile: null|string, rate: float} */
    private static array $default = ['addLineNumbers' => true, 'contextLines' => 3, 'fromFile' => null, 'rate' => 1.5];
    private static array $plain = ['f' => false, 'n' => 7, 's' => 'x'];
    private static array $ints = ['a' => 1, 'b' => 2];
    private static array $list = [1, 'two', 3.0, true];

    /** @param array<string, mixed> $options */
    public static function run(array $options): void
    {
        $o = array_merge(self::$default, $options);
        var_dump(\is_bool($o['addLineNumbers']), $o['contextLines'], $o['fromFile'], $o['rate']);
        var_dump(self::$default['addLineNumbers'], self::$default['rate']);
        show(self::$plain);
        show(self::$ints);
        foreach (self::$list as $v) { var_dump($v); }
        show(array_merge(self::$plain, self::$ints));
    }
}

Opts::run(['fromFile' => 'a.php']);
Opts::run([]);
