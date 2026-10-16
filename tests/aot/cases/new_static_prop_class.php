<?php
class K { public function __construct(public string $e = "") {} }
abstract class T {
    protected static ?string $class = null;
    protected static function getKernelClass(): string { return K::class; }
    public static function a(): string { static::$class ??= static::getKernelClass(); return var_export(static::$class, true); }
    public static function b(): string { $k = new static::$class("x"); return get_class($k); }
    public static function c(): string { $n = static::$class; $k = new $n("y"); return get_class($k); }
}
class U extends T {}
echo U::a(), "\n", U::b(), "\n", U::c(), "\n";
