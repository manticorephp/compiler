<?php
namespace App;
trait Hello { public function hi() { return 'hi'; } }
trait World { public function w() { return 'w'; } }
trait Both { use Hello, World; }
class Plain {}
class A { use Hello; }
class B extends A { use World; }
class C { use Both; }
enum E: string { use World; case X = 'x'; }
interface I {}
print_r(class_uses(new A));
print_r(class_uses('App\B'));
print_r(class_uses('\App\C'));
print_r(class_uses(Both::class));
print_r(class_uses(E::class));
print_r(class_uses(Plain::class));
print_r(class_uses(I::class));
var_dump(class_uses(new Plain));
print_r(class_uses('app\a'));
