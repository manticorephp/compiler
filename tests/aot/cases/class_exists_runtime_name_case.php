<?php
// class_exists() / interface_exists() / trait_exists() with a runtime name answer false for a different-case spelling php accepts
namespace App\Deep;
trait T1 {}
class C {}
interface I {}
$names = ['app\deep\c', 'APP\DEEP\C', 'app\deep\i', 'app\deep\t1'];
foreach ($names as $n) {
    var_dump(\class_exists($n, false), \interface_exists($n, false), \trait_exists($n, false));
}
