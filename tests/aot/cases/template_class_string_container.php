<?php
// A DI container's `get(Foo::class)` with `@template T` + `class-string<T>` yields a Foo.
interface Greeter { public function greet(string $who): string; }
class Logger { public string $name = 'log'; public int $hits = 0; public function hello(): string { $this->hits++; return "hello from " . $this->name; } }
class Polite implements Greeter { public function greet(string $who): string { return "Good day, $who"; } }
class Container {
    /** @var array<string, object> */
    private array $s = [];
    public function set(string $id, object $o): void { $this->s[$id] = $o; }
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object { return $this->s[$id]; }
}
class Child extends Container {}
$c = new Child();
$c->set(Logger::class, new Logger());
$c->set(Greeter::class, new Polite());
$l = $c->get(Logger::class);
echo $l->hello(), "\n", $l->name, "\n";
echo $c->get(Logger::class)->hello(), " ", $c->get(Logger::class)->hits, "\n";
$l->name = 'renamed';
echo $c->get(\Logger::class)->name, "\n";
echo $c->get(Greeter::class)->greet('Ada'), "\n";
var_dump($c->get(Logger::class) instanceof Logger);
echo get_class($c->get(Greeter::class)), "\n";
