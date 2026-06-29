<?php
class Animal {
    public string $name;
    public function __construct(string $name) {
        $this->name = $name;
    }
    public function describe(): string {
        return "Animal:" . $this->name;
    }
}
class Dog extends Animal {
    public function bark(): string {
        return $this->name . " says woof";
    }
}
$d = new Dog("Rex");
echo $d->describe(), ";", $d->bark();
