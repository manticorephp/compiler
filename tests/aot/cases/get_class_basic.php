<?php
class Animal {}
class Dog extends Animal {}
$d = new Dog();
$a = new Animal();
echo get_class($d), ",", get_class($a);
