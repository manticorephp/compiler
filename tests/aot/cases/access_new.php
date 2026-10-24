<?php
abstract class Ab {}
interface I {}
enum E { case X; }
enum V: int { case One = 1; }
class P { private function __construct() {} public static function make() { return new P; } public static function viaStatic() { return new static; } }
class Q { protected function __construct() {} public static function make() { return new Q; } }
class Q2 extends Q { public static function build() { return new Q2; } }
class Pub { public function __construct(public int $x = 1) {} }
function mk(string $c) { try { new $c; echo "created $c\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
try { new Ab; echo "created\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { new I; echo "created\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { new E; echo "created\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { new P; echo "created\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { new Q; echo "created\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo get_class(P::make()), get_class(P::viaStatic()), get_class(Q::make()), get_class(Q2::build()), "\n";
echo (new Pub(3))->x, "\n";
try { $e = E::X; $e->name = 'Y'; echo "no\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $v = V::One; $v->value = 2; echo "no\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo V::One->value, E::X->name, "\n";
