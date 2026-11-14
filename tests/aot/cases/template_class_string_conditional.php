<?php
// Conditional returns `($id is class-string<T> ? T : object)` over a `string|class-string<T>` param type the call by a class literal, through interface inheritance.
interface PsrContainer {
    /** @return mixed */
    public function get(string $id);
}
interface Container extends PsrContainer {
    public const NULL_ON_INVALID = 2;
    /**
     * @template T of object
     * @template B of 0|1|2
     * @param string|class-string<T> $id
     * @param B $invalid
     * @return ($id is class-string<T> ? (B is 0|1 ? T : T|null) : (B is 0|1 ? object : object|null))
     */
    public function get(string $id, int $invalid = 1): ?object;
    /**
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T|object : object)
     */
    public function loose(string $id): object;
}
class Clock { public function now(): string { return 'noon'; } }
class Mail { public string $to = 'ops'; }
class Box implements Container {
    /** @var array<string, object> */
    private array $s = [];
    public function set(string $id, object $o): void { $this->s[$id] = $o; }
    public function get(string $id, int $invalid = 1): ?object { return $this->s[$id] ?? null; }
    public function loose(string $id): object { return $this->s[$id]; }
}
function run(Container $c): void {
    echo $c->get(Clock::class)->now(), "\n";
    echo $c->get(Mail::class)->to, "\n";
    var_dump($c->get(Mail::class, Container::NULL_ON_INVALID) instanceof Mail);
    $m = $c->get('mailer');
    echo get_class($m), "\n";
    var_dump($c->get('missing', Container::NULL_ON_INVALID));
    echo $c->loose(Clock::class)->now(), "\n";
}
$b = new Box();
$b->set(Clock::class, new Clock());
$b->set(Mail::class, new Mail());
$b->set('mailer', new Mail());
run($b);
echo $b->get(Clock::class)->now(), "\n";
