<?php
class Animal {}
class Dog extends Animal {}
class Cat extends Animal {}
$d = new Dog();
echo ($d instanceof Dog) ? "y" : "n", ",", ($d instanceof Animal) ? "y" : "n", ",", ($d instanceof Cat) ? "y" : "n";
