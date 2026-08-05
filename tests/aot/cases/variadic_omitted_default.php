<?php

/*
 * A FIXED parameter the call omits still owns its slot when a variadic
 * follows it: the pack must land in the variadic's slot, not slide into the
 * first omitted one. It used to slide, so `?int $a = null` read back as the
 * empty pack's POINTER — `$a === null` was false and passing $a on stored a
 * pointer into an int property (PDO::query()'s default fetch mode).
 */

function f(string $q, ?int $a = null, mixed ...$rest): void
{
    printf("f: isnull=%d raw=%d cnt=%d\n", $a === null ? 1 : 0, (int)$a, count($rest));
}

function g(int $a = 3, string $b = 'd', mixed ...$rest): void
{
    printf("g: a=%d b=%s cnt=%d\n", $a, $b, count($rest));
}

class C
{
    public const K = 7;

    public function m(string $q, ?int $a = null, mixed ...$rest): void
    {
        printf("C::m: isnull=%d cnt=%d\n", $a === null ? 1 : 0, count($rest));
    }

    /** An omitted default resolves against the CALLEE's class, not the call site. */
    public function k(string $q, int $a = self::K, mixed ...$rest): void
    {
        printf("C::k: a=%d cnt=%d\n", $a, count($rest));
    }

    public static function s(?string $a = null, mixed ...$rest): void
    {
        printf("C::s: isnull=%d cnt=%d\n", $a === null ? 1 : 0, count($rest));
    }
}

f('x');
f('x', 7);
f('x', 7, 'z');

g();
g(9);
g(9, 'z');
g(9, 'z', 1, 2);

$c = new C();
$c->m('x');
$c->m('x', 1, 2, 3);
$c->k('x');
$c->k('x', 4, 5);
C::s();
C::s('v', 1);

$args = [1, 2];
f('x', 7, ...$args);
