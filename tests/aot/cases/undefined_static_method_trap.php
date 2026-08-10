<?php
trait Asserts
{
    public static function ok(string $m): string { return self::assertThat($m); }
}
class Runner
{
    use Asserts;
    public static function safe(): string { return "safe"; }
}
echo Runner::safe(), "\n";
try { echo Runner::ok("x"), "\n"; }
catch (\Error $e) { echo "caught: ", $e->getMessage(), "\n"; }
