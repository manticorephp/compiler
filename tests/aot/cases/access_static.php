<?php
class A {
    private static function f() { return 'f'; }
    protected static function g() { return 'g'; }
    private static int $s = 2;
    protected static int $ps = 3;
    const PUB = 1;
    private const Q = 4;
    protected const R = 5;
    public static function all() { return self::f() . static::g() . self::$s . self::$ps . self::Q . self::R . static::Q; }
    public static function setS($v) { self::$s = $v; return self::$s; }
    public function inst() { return 'inst'; }
    public function callInst() { return self::inst() . A::inst(); }
}
class B extends A {
    public static function sub() { return parent::g() . self::g() . static::$ps . self::R . parent::R; }
    public function viaParent() { return parent::inst(); }
    public static function hasNoThis() { return A::inst(); }
}
class C { public function inst() { return 'c'; } public static function bad() { return A::inst(); } }
class D {
    private static function secret() { return 's'; }
    public static function __callStatic($n, $a) { return "cs:$n"; }
}
function e(callable $f) { try { echo $f(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
try { echo A::f(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo A::g(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo A::$s, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { A::$s = 1; echo "no\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo A::$ps, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo A::Q, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo B::R, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo A::inst(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo C::bad(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo B::hasNoThis(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo A::PUB, "\n";
echo A::all(), "\n";
echo A::setS(9), "\n";
echo B::sub(), "\n";
echo (new A)->callInst(), "\n";
echo (new B)->viaParent(), "\n";
echo D::secret(), "\n";
echo D::other(), "\n";
