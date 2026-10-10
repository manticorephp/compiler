<?php
// `use A { m as alias; }` inside a trait must still give the alias to a class that uses the outer trait,
// even when the outer trait overrides `m` itself (symfony's MicroKernelTrait does exactly this).
trait A {
    private function foo(): array { return ['A']; }
    protected function bar(): string { return 'barA'; }
    public function baz(): string { return 'bazA'; }
}
trait Other {
    public function baz(): string { return 'bazOther'; }
}
trait B {
    use A {
        foo as private doFoo;
        bar as protected doBar;
    }
    use Other { Other::baz insteadof A; }
    private function foo(): array { return $this->doFoo() ?: ['fallback']; }
    protected function bar(): string { return 'B+' . $this->doBar(); }
}
class C { use B; public function run(): string { return implode(',', $this->foo()) . '|' . $this->bar() . '|' . $this->baz(); } }
echo (new C())->run(), "\n";
class D extends C {}
echo (new D())->run(), "\n";
