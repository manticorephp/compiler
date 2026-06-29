<?php
// Property overloading: an undeclared property routes reads to __get, writes to
// __set, isset()/empty() to __isset, unset() to __unset. The name is a string.
class Container {
    /** @var array<string,mixed> */
    private array $store = [];
    public function __get(string $name): mixed { return $this->store[$name]; }
    public function __set(string $name, mixed $value): void { $this->store[$name] = $value; }
    public function __isset(string $name): bool { return isset($this->store[$name]); }
    public function __unset(string $name): void { unset($this->store[$name]); }
}

$c = new Container();
$c->title = "Hello";
$c->count = 5;
$c->active = true;

echo $c->title, " ", $c->count, "\n";
var_dump($c->active);
var_dump(isset($c->title));
var_dump(isset($c->missing));
unset($c->title);
var_dump(isset($c->title));

// __get that synthesizes from the name.
class Echoer {
    public function __get(string $name): string { return "[" . $name . "]"; }
}
$e = new Echoer();
echo $e->foo, $e->bar, "\n";
