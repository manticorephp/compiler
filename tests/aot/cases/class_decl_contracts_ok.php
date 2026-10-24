<?php
// Declarations PHP accepts: the class-declaration checks must not reject them.
interface I { const C = 1; public function f(): string; public static function s(): int; }
interface J extends I { public function g(); }
abstract class Base implements J {
    protected const P = 'p';
    public $pub = 1;
    protected $prot = 2;
    private $priv = 3;
    public function f(): string { return 'f'; }
    abstract protected function h();
    private function hidden() { return 'base'; }
    private function fp() {}
    public function __construct() {}
}
class Impl extends Base {
    public const C = 2;              // interface constants may be overridden
    public const P = 'q';            // protected -> public is wider
    public $prot = 20;               // protected -> public
    private $priv = 30;              // parent private is not inherited
    public function g() { return 'g'; }
    public static function s(): int { return 7; }
    public function h() { return 'h'; }      // protected -> public
    private function hidden() { return 'impl'; }
    public function fp() { return 'fp'; }    // parent's is private: no override
    public function __construct() { parent::__construct(); }
    public function callHidden() { return $this->hidden(); }
}
trait T {
    abstract public function need(): int;
    public static function make(): static { return new static(); }
    public function viaTrait() { return 'T'; }
}
trait U { use T; }
class UsesT { use U; public function need(): int { return 5; } }
class Parent2 { public function need(): int { return 9; } }
class UsesT2 extends Parent2 { use T; }          // abstract trait method met by inherited one
class Aliased { use T { viaTrait as protected aliased; } public function need(): int { return 1; } }
enum E: string implements I {
    case A = 'a';
    public function f(): string { return $this->value; }
    public static function s(): int { return 3; }
}
final class Leaf extends Impl {}
abstract class AbsNoImpl implements I {}
$anon = new class extends Impl { public function g() { return 'anon'; } };
$i = new Impl();
echo $i->f(), $i->g(), $i->h(), $i->callHidden(), $i->fp(), Impl::C, Impl::P, Impl::s(), "\n";
echo UsesT::make()->need(), (new UsesT2)->need(), (new Aliased)->need(), E::A->f(), E::s(), "\n";
echo $anon->g(), (new Leaf)->g(), "\n";
