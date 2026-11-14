<?php
// Superset strictness (no php oracle): an implementation inheriting an interface's / abstract parent's `@return T` (class-string<T>) that returns another class throws TypeError where php carries on; expected written by hand.
interface Locator {
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object;
}
abstract class Finder {
    /**
     * @template T of object
     * @param class-string<T> $cls
     * @return T|null
     */
    abstract public function find(string $cls): ?object;
}
class Foo { public function who(): string { return 'foo'; } }
class Bar { public function who(): string { return 'bar'; } }
class BadLocator implements Locator {
    public function get(string $id): object { return new Foo(); }
}
class BadFinder extends Finder {
    public function find(string $cls): ?object { return new Foo(); }
}
class Holder { public function __construct(public Locator $loc) {} }
function attempt(string $form, callable $f): void {
    try { echo $form, ": ", $f(), "\n"; }
    catch (TypeError $e) { echo $form, ": ", get_class($e), ": ", $e->getMessage(), "\n"; }
}
function viaParam(Locator $l): string { return $l->get(Bar::class)->who(); }
function viaAbstract(Finder $f): string { return $f->find(Bar::class)->who(); }
$bad = new BadLocator();
attempt('ok', fn() => viaParamOk($bad));
function viaParamOk(Locator $l): string { return $l->get(Foo::class)->who(); }
attempt('param', fn() => viaParam($bad));
attempt('property', fn() => (new Holder($bad))->loc->get(Bar::class)->who());
attempt('concrete', fn() => $bad->get(Bar::class)->who());
attempt('abstract', fn() => viaAbstract(new BadFinder()));
echo "done\n";
