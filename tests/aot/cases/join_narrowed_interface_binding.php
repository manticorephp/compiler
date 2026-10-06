<?php
interface Opt { public function getName(): string; }
interface DepOpt extends Opt { public function msg(): string; }
final class O implements Opt {
    public function __construct(private string $n) {}
    public function getName(): string { return $this->n; }
}
final class D implements DepOpt {
    public function getName(): string { return 'd'; }
    public function msg(): string { return 'deprecated'; }
}
final class A implements Opt {
    public function getName(): string { return 'al'; }
    public function getAlias(): string { return 'alias'; }
}
interface Resolver {
    /** @return list<Opt> */
    public function getOptions(): array;
}
final class R implements Resolver {
    public function getOptions(): array { return [new O('a'), new D(), new A()]; }
}
function gen(Resolver $r): string {
    $out = '';
    foreach ($r->getOptions() as $option) {
        $out .= $option->getName();
        if ($option instanceof DepOpt) {
            $out .= ' ' . $option->msg();
        }
        if ($option instanceof A) {
            $out .= " ({$option->getAlias()})";
        }
        $out .= ' ' . $option->getName() . ';';
    }
    return $out;
}
echo gen(new R()), "\n";
