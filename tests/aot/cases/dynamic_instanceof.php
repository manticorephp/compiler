<?php
interface Animal {}
class Base {}
class Dog extends Base implements Animal {}
class Cat {}
$d = new Dog();
$c = new Cat();
foreach (["Dog","Base","Animal","Cat"] as $cls) {
    var_dump($d instanceof $cls);
}
var_dump($c instanceof $cls);
$n = null;
$k = "Dog";
var_dump($n instanceof $k);
