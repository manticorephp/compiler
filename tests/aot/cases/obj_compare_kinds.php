<?php

// Object comparison beyond plain properties: closures and enum cases compare by
// identity and are otherwise uncomparable; PRIVATE and PROTECTED state takes
// part; DateTime compares by instant (across zones and against
// DateTimeImmutable); a union of enum cases boxes each case; and a cyclic graph
// throws where a long acyclic one compares all the way down.

enum E { case A; case B; }
enum F { case X; case Z; }

function u(int $k): E|F { return $k === 0 ? E::B : F::Z; }
function mixedOf(mixed $v): mixed { return $v; }

// Closures.
$f = fn() => 1;
$g = fn() => 1;
$cf = mixedOf($f);
$cg = mixedOf($g);
var_dump($f == $g, $f == $f, $cf == $cg, $cf == $cf, $cf <=> $cg, $cf <=> $cf);

// A union of enum classes.
var_dump(u(0), u(1), u(0) == u(1), u(0) == u(0), u(0) <=> u(1), u(0) === E::B);

// Enum cases through a cell and statically.
$ca = mixedOf(E::A);
$cb = mixedOf(E::B);
var_dump($ca == $cb, $ca == $ca, $ca <=> $cb, $cb <=> $ca, $ca <=> $ca, $ca < $cb, $ca > $cb, $ca <= $ca, $ca >= $cb);
var_dump(E::A <=> E::B, E::A < E::B, E::A > E::B, E::A <= E::A, E::A == E::A, E::A == E::B, E::A <=> E::A);
var_dump(E::A == F::X, mixedOf(E::A) == mixedOf(F::X));

// Private / protected state.
class P { public function __construct(private int $c, protected string $s = 'x') {} }
$p1 = new P(1); $p2 = new P(2); $p1b = new P(1); $p3 = new P(1, 'y');
var_dump($p1 == $p2, $p1 == $p1b, $p1 <=> $p2, $p2 <=> $p1, $p1 <=> $p1b, $p1 == $p3, $p1 < $p2, $p2 > $p1);
$m1 = mixedOf($p1); $m2 = mixedOf($p2);
var_dump($m1 == $m2, $m1 <=> $m2, $m1 < $m2);

// Different classes through cells are uncomparable.
class Q { public int $c = 1; }
$mq = mixedOf(new Q()); $mp = mixedOf(new P(1));
var_dump($mq == $mp, $mq <=> $mp, $mp <=> $mq, $mq < $mp, $mq > $mp);

// DateTime compares by instant.
$t1 = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
$t4 = new DateTime('2021-06-01 00:00:00', new DateTimeZone('UTC'));
$tz = new DateTime('2020-01-01 02:00:00', new DateTimeZone('Europe/Kyiv'));
$ti = new DateTimeImmutable('2021-06-01 00:00:00', new DateTimeZone('UTC'));
var_dump($t1 < $t4, $t4 > $t1, $t1 <=> $t4, $t1 == $tz, $t1 <=> $tz, $ti == $t4, $ti > $t1, $t1 < $ti);

// A long acyclic chain compares all the way down; a cycle throws.
final class N { public ?N $next = null; public function __construct(public int $v) {} }
function chain(int $n, int $last): N { $h = new N($last); for ($i = 0; $i < $n; $i++) { $x = new N(0); $x->next = $h; $h = $x; } return $h; }
var_dump(chain(70, 1) == chain(70, 1), chain(70, 1) == chain(70, 2), chain(70, 1) <=> chain(70, 2));
$c1 = new N(1); $c1->next = $c1;
$c2 = new N(1); $c2->next = $c2;
try { var_dump($c1 == $c2); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_dump($c1 <=> $c2); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
var_dump($c1 == $c1);
// After a throw, comparing still works.
var_dump(new N(3) == new N(3));

// Uninitialized typed properties on both sides compare equal.
final class D { public int $v = 1; }
final class U { public D $d; public string $s; public int $n = 0; }
var_dump(new U == new U, new U <=> new U);
$u1 = new U; $u1->s = 'a'; $u2 = new U; $u2->s = 'a';
var_dump($u1 == $u2);
