<?php
class Person {
    public function __construct(
        public string $name,
        public int $age,
    ) {}
    public function greeting(): string {
        return "Hi, I am " . $this->name . " (" . $this->age . ")";
    }
}
$p = new Person("Alice", 30);
echo $p->greeting();
