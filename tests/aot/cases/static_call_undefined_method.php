<?php

// A `self::` / `static::` / `parent::` call to a method NOTHING declares is
// php's runtime Error, raised where the call is reached — so a reference behind
// a guard that never fires costs nothing. It used to leave `$this` dangling and
// refuse the build: `methodIsStatic` answers "instance" for a method it cannot
// resolve (on purpose, so `parent::m()` still receives the receiver), and that
// default forwarded a local a STATIC frame never had.
//
// symfony/clock's DatePoint is the witness — `static::getLastErrors()` inside a
// throw expression that only runs when the date failed to parse. php's
// DateTimeImmutable declares it, ours does not, and that one reference stopped
// an entire audit tier from building.

final class Guarded
{
    public static function neverReached(): string
    {
        if (function_exists('definitely_not_a_real_function_xyz')) {
            return (string)static::notDeclaredAnywhere();
        }
        return 'guard held';
    }
}

echo Guarded::neverReached(), "\n";

// Reached: throws, catchable, with php's own message.
final class Reached
{
    public static function go(): string
    {
        return static::missingOne();
    }
}

try {
    echo Reached::go(), "\n";
    echo "MUST NOT PRINT\n";
} catch (\Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// A known static method through `static::` is untouched.
class KnownBase
{
    public static function make(string $s): string { return 'base:' . $s; }
}
final class KnownKid extends KnownBase
{
    public static function make(string $s): string { return parent::make($s) . '/kid'; }
    public static function viaStatic(string $s): string { return static::make($s); }
}
echo KnownKid::viaStatic('x'), "\n";

// An INSTANCE method still forwards `$this` to an inherited instance method —
// that is the default the fix had to keep.
class InstBase
{
    protected string $tag = 'base';
    public function describe(): string { return 'I am ' . $this->tag; }
}
final class InstKid extends InstBase
{
    protected string $tag = 'kid';
    public function describe(): string { return parent::describe() . '!'; }
}
echo (new InstKid())->describe(), "\n";

// `__callStatic` is php's hook for exactly "this method is not declared", so a
// class that defines one must NOT be converted.
final class Magic
{
    public static function __callStatic(string $name, array $args): string
    {
        return 'magic:' . $name . '/' . count($args);
    }
    public static function route(): string { return static::anything(1, 2); }
}
echo Magic::route(), "\n";
