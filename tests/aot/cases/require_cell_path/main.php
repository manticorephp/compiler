<?php
// `require $file` where the path came through an untyped parameter (a string
// CELL) — symfony/polyfill-mbstring's getData(): realpath read the tag bits.
final class Tables
{
    private static function getData($file)
    {
        if (file_exists($file = __DIR__ . '/' . $file . '.php')) {
            return require $file;
        }
        return false;
    }
    public static function lower(string $s): string
    {
        static $map = null;
        if (null === $map) { $map = self::getData('data'); }
        return strtr($s, $map);
    }
}
echo Tables::lower('AXB'), "\n";
