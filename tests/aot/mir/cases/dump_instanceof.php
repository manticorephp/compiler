<?php
class Animal {}
class Dog extends Animal {}
function check(Animal $a): bool {
    return $a instanceof Dog;
}
echo check(new Dog()) ? "y" : "n";
