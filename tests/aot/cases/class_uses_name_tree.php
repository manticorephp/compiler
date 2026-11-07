<?php
// class_uses() resolves any spelling of a declared name, an object, a trait, and answers false for an unknown one
namespace App\Deep;

trait T1 {}
trait T2 { use T1; }
class A { use T1; }
class B extends A { use T2, T1; }
class C {}
enum E { use T1; case X; }

foreach (['App\\Deep\\A', 'app\\deep\\a', '\\App\\Deep\\A', 'App\\Deep\\B', 'app\\deep\\b', 'App\\Deep\\C', 'App\\Deep\\E', 'App\\Deep\\T2', 'App\\Deep\\T1', 'Nope', 'App\\Deep\\Nope', ''] as $n) {
    echo var_export($n, true), ' => ', json_encode(@\class_uses($n)), "\n";
}
echo json_encode(\class_uses(new B)), json_encode(\class_uses(new C)), "\n";
