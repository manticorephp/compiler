<?php
// A PSR-style ContainerInterface's `@template T` + `class-string<T>` types `get(Foo::class)` through an interface-typed param, property, and an undocumented implementation.
interface ContainerInterface {
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): mixed;
}
class Mailer { public string $from = 'noreply'; public function send(string $to): string { return "{$this->from} -> $to"; } }
class Container implements ContainerInterface {
    /** @var array<string, object> */
    private array $s = [];
    public function set(string $id, object $o): void { $this->s[$id] = $o; }
    /** {@inheritdoc} */
    public function get(string $id): mixed { return $this->s[$id]; }
}
class Service {
    public function __construct(private ContainerInterface $container) {}
    public function run(): string { return $this->container->get(Mailer::class)->send('svc') . ' ' . $this->container->get(Mailer::class)->from; }
}
function viaParam(ContainerInterface $c): string { $m = $c->get(Mailer::class); return $m->send('param') . ' ' . $m->from; }
$c = new Container();
$c->set(Mailer::class, new Mailer());
echo viaParam($c), "\n";
echo (new Service($c))->run(), "\n";
$direct = $c->get(Mailer::class);
$direct->from = 'admin';
echo $direct->send('concrete'), "\n";
echo viaParam($c), "\n";
