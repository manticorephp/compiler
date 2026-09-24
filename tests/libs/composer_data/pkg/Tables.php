<?php
namespace Acme\Mb;

final class Tables
{
    private static function getData($file)
    {
        if (file_exists($file = __DIR__ . '/Resources/unidata/' . $file . '.php')) {
            return require $file;
        }
        return false;
    }

    public static function lower(string $s): string
    {
        static $map = null;
        if (null === $map) { $map = self::getData('lowerCase'); }
        return \is_array($map) ? \strtr($s, $map) : 'NO-TABLE';
    }
}
