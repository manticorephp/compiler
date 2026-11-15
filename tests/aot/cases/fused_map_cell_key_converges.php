<?php
// A fused array_map over a list of cells or a string-keyed array builds a cell-keyed result; inference and NarrowReturns must agree on it or the narrow fixpoint never closes.
abstract class Desc {
    /** @return array<string, string[]> */
    protected function rev(array $names): array {
        $r = [];
        foreach ($names as $name => $id) { $r[$id][] = $name; }
        return $r;
    }
    abstract protected function describe(array $names, array $options = []): void;
}
final class Md extends Desc {
    protected function describe(array $names, array $options = []): void {
        foreach (['home', 'blog'] as $name) {
            echo $name, "\n";
            if (($show ??= $options['show_aliases'] ?? false) && $aliases = ($rev ??= $this->rev($names))[$name] ?? []) {
                echo implode("\n", array_map(static fn (string $a): string => sprintf('  - %s', $a), $aliases)), "\n";
            }
        }
    }
    public function run(): void { $this->describe(['index' => 'home', 'start' => 'home', 'posts' => 'blog', 7 => 'blog'], ['show_aliases' => true]); }
}
(new Md())->run();
interface Ext { public function alias(): string; }
final class A implements Ext { public function alias(): string { return 'a'; } }
final class B implements Ext { public function alias(): string { return 'b'; } }
final class Loader {
    /** @var array<string, Ext> */
    private array $exts = [];
    public function add(string $ns, Ext $e): void { $this->exts[$ns] = $e; }
    public function names(): string {
        return implode(', ', array_map(fn (Ext $e): string => $e->alias(), $this->exts));
    }
    /** @return array<string, string> */
    public function keyed(): array { return array_map(fn (Ext $e): string => strtoupper($e->alias()), $this->exts); }
}
$l = new Loader();
$l->add('first', new A()); $l->add('7', new B()); $l->add('third', new A());
echo $l->names(), "\n";
foreach ($l->keyed() as $k => $v) { var_dump($k); echo $v, "\n"; }
