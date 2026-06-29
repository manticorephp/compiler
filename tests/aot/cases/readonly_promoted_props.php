<?php
final class Person {
    public function __construct(
        public readonly string $name,
        public readonly int $age,
    ) {}
    public function label(): string {
        return $this->name . "=" . $this->age;
    }
}
$p = new Person("Ada", 36);
echo $p->label();
