<?php
// An erased receiver's call is coerced against a holder that can take it, not one whose required params the call omits.
class Pass { private function getNamespace(string $seed, string $id): string { return $seed . $id; } public function run(): string { return $this->getNamespace("a", "b"); } }
abstract class Bu { public function getNamespace(): string { return "NS:" . static::class; } }
class FooBundle extends Bu {}
class K {
    public array $bundles = [];
    public function __construct() { $this->bundles['F'] = new FooBundle(); }
    public function names(): array { $o = []; foreach ($this->bundles as $n => $b) { $o[$n] = $b->getNamespace(); } return $o; }
}
function viaMixed(mixed $b): string { return $b->getNamespace(); }
print_r((new K())->names());
echo viaMixed(new FooBundle()), "\n";
echo (new Pass())->run(), "\n";
