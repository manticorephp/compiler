<?php
// A by-ref param with a default, called WITHOUT the argument, through a static
// call, a method call and a constructor: the callee writes into a throwaway
// slot, never through the default value used as an address.
final class Preg
{
    public static function match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): bool
    {
        $result = @preg_match($pattern, $subject, $matches, $flags, $offset);
        if (false !== $result && \PREG_NO_ERROR === preg_last_error()) {
            return 1 === $result;
        }
        throw new \RuntimeException('x');
    }
}
var_dump(Preg::match('/^@[a-z]+$/i', '@PSR12'));
var_dump(Preg::match('/^(\d+)$/', '42', $m), $m);
final class Box { public function m(string $re, string $s, ?array &$out = null): int { return preg_match($re, $s, $out); } public function __construct(?array &$x = null) { $x = [1]; } }
$b = new Box();
var_dump((new Box())->m('/(a)/', 'xa'));
var_dump($b->m('/(a)/', 'xa', $mm), $mm);
