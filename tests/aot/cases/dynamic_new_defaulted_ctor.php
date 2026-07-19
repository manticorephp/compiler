<?php
class User {
    public string $name = "ann";
    public int $id = 0;
    public function __construct(int $id = 0) { $this->id = $id; }
    public function label(): string { return $this->name; }
}
class Admin {
    public string $name = "adm";
    public function __construct(int $id = 0) {}
    public function label(): string { return "A:" . $this->name; }
}
function make(string $cls): object { return new $cls(); }   // defaulted ctor, argc=0
$o = make('User');
echo $o->name, "\n";
echo $o->label(), "\n";
echo $o->id, "\n";
$a = make('Admin');
echo $a->name, "\n";
echo $a->label(), "\n";
