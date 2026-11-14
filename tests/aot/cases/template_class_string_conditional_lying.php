<?php
// Superset strictness (no php oracle): a conditional `@return ($id is class-string<T> ? T : object)` whose implementation returns another class throws TypeError where php carries on; expected written by hand.
interface Locator {
    /**
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object;
}
class Foo { public function who(): string { return 'foo'; } }
class Bar { public function who(): string { return 'bar'; } }
class Bad implements Locator {
    public function get(string $id): object { return new Foo(); }
}
function probe(Locator $l): void {
    echo $l->get('anything')->who(), "\n";
    echo $l->get(Foo::class)->who(), "\n";
    try { $l->get(Bar::class)->who(); echo "no throw\n"; }
    catch (TypeError $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
probe(new Bad());
