<?php
// Typed class constants (PHP 8.3): the type hint is parsed and the value
// resolves like an untyped const.
class Config
{
    const int MAX = 100;
    const string NAME = "cfg";
    public const float RATIO = 1.25;
    private const bool DEBUG = false;

    public function ratio(): float { return self::RATIO; }
    public function dbg(): bool { return self::DEBUG; }
}

interface HasVersion
{
    const int VERSION = 7;
}

class Impl implements HasVersion {}

echo Config::MAX, "\n";
echo Config::NAME, "\n";
echo Config::RATIO, "\n";
$c = new Config();
printf("%.2f\n", $c->ratio());
var_dump($c->dbg());
echo Impl::VERSION, "\n";
