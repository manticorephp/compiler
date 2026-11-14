<?php
// A `@return ?T` bound by `class-string<T>` passes null through and yields the class otherwise.
class Svc { public function id(): int { return 7; } }
class Locator {
    /** @var array<string, object> */
    private array $s = [];
    public function add(object $o): void { $this->s[get_class($o)] = $o; }
    /**
     * @template T
     * @param class-string<T> $cls
     * @return T|null
     */
    public function find(string $cls): ?object { return $this->s[$cls] ?? null; }
}
$loc = new Locator();
$none = $loc->find(Svc::class);
var_dump($none);
var_dump($none === null);
$loc->add(new Svc());
$s = $loc->find(Svc::class);
echo $s?->id(), "\n";
echo $s === null ? "null" : "found " . $s->id(), "\n";
