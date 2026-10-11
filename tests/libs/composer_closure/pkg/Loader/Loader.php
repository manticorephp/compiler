<?php
namespace Acme\Cfg\Loader;

use Acme\Cfg\Configurator;

class Loader
{
    public function load(string $name): Configurator
    {
        $path = \dirname(__DIR__) . '/Resources/config/' . $name;
        $c = new Configurator();
        $r = \Closure::bind(static function ($p) { return include $p; }, null, ProtectedLoader::class)($path);
        if (\is_callable($r)) { $r($c); }
        return $c;
    }

    public function data(string $name): mixed
    {
        return \Closure::bind(static function ($p) { return include $p; }, null, ProtectedLoader::class)(\dirname(__DIR__) . '/Resources/config/' . $name);
    }
}
