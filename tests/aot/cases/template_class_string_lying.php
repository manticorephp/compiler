<?php
// Superset strictness (no php oracle): a `@return T` bound by `class-string<T>` that returns another class throws TypeError where php carries on; expected written by hand.
class Foo { public function who(): string { return 'foo'; } }
class Bar { public function who(): string { return 'bar'; } }
class Registry {
    /** @var array<string, object> */
    public array $s = [];
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object { return $this->s[$id]; }
}
$r = new Registry();
$r->s[Foo::class] = new Foo();
$r->s[Bar::class] = new Foo();
echo $r->get(Foo::class)->who(), "\n";
try {
    echo $r->get(Bar::class)->who(), "\n";
} catch (TypeError $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
echo "done\n";
