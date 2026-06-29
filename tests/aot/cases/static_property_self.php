<?php
class Config {
    public static string $host = "localhost";
    public static function set(string $h): void { self::$host = $h; }
    public static function get(): string { return self::$host; }
}
Config::set("example.com");
echo Config::get();
