<?php
class Config {
    const VERSION = 42;
    const NAME = "cfg";
    public static int $count = 7;
    public static function make(int $x): string { return "made:" . $x; }
    public static function tag(): string { return "cfgtag"; }
}
class Other {
    const VERSION = 99;
    public static function make(int $x): string { return "other:" . $x; }
}
$cls = "Config";
echo $cls::VERSION, "\n";
echo $cls::NAME, "\n";
echo $cls::$count, "\n";
echo $cls::make(5), "\n";
echo $cls::tag(), "\n";
$cls = "Other";
echo $cls::VERSION, "\n";
echo $cls::make(3), "\n";
