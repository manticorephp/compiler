<?php
// ReflectionClass / ReflectionObject::getFileName() names the file the class was declared in
// (Symfony's AbstractKernel::getProjectDir walks up from it).
namespace App;
class Kernel {}
final class Child extends Kernel {}
$a = new \ReflectionClass(Kernel::class);
$b = new \ReflectionObject(new Child());
echo basename($a->getFileName()), "\n";
echo basename($b->getFileName()), "\n";
echo $a->getFileName() === __FILE__ ? "same file" : "other file", "\n";
var_dump((new \ReflectionClass(\stdClass::class))->getFileName());
