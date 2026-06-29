<?php
trait Named {
    public function label(): string {
        return "name=" . $this->name;
    }
}
class Person {
    use Named;
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
}
$p = new Person("Ada");
echo $p->label();
