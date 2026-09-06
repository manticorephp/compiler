<?php

// A STATIC method reached through `->`, and a trait's `private static` reached
// through `self::`. php passes NO receiver in either case; lowering them as
// ordinary method calls prepended one, and every argument shifted a place —
// the first parameter read the object pointer and the last read past the frame.
// Silently: the ABI is uniformly i64, so nothing warns and nothing fails to
// link. Both shapes are ordinary php and symfony uses them.

class PlainCls
{
    private static function s3(string $a, string $b, int $c): string { return $a . '|' . $b . '|' . $c; }
    public static function pubs3(string $a, string $b, int $c): string { return $a . '|' . $b . '|' . $c; }
    public function viaThis(): string { return $this->s3('x', 'y', 3); }
    public function viaSelf(): string { return self::s3('x', 'y', 3); }
    public function viaSelfPub(): string { return self::pubs3('x', 'y', 3); }
}

trait TrOnly
{
    private static function t3(string $a, string $b, int $c): string { return $a . '|' . $b . '|' . $c; }
    private function n3(string $a, string $b, int $c): string { return $a . '|' . $b . '|' . $c; }
    public function traitSelf(): string { return self::t3('x', 'y', 3); }
    public function traitThis(): string { return $this->t3('x', 'y', 3); }
    public function traitNonStatic(): string { return $this->n3('x', 'y', 3); }
}

class TrHost { use TrOnly; }

$p = new PlainCls();
echo 'class  this   : ', $p->viaThis(), "\n";
echo 'class  self   : ', $p->viaSelf(), "\n";
echo 'class  selfP  : ', $p->viaSelfPub(), "\n";
echo 'class  static : ', PlainCls::pubs3('x', 'y', 3), "\n";

$t = new TrHost();
echo 'trait  self   : ', $t->traitSelf(), "\n";
echo 'trait  this   : ', $t->traitThis(), "\n";
echo 'trait  nonstat: ', $t->traitNonStatic(), "\n";
