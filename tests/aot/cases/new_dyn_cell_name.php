<?php
// `new $class()` where $class is a string CELL (read out of an erased static
// list — php-cs-fixer registerBuiltInFixers): strcmp got the tag bits.
namespace App\Fixer;
interface FixerInterface { public function getName(): string; }
final class AFixer implements FixerInterface { public function getName(): string { return 'a_fix'; } }
final class BFixer implements FixerInterface { public function getName(): string { return 'b_fix'; } }
final class Factory
{
    /** @var array<string, FixerInterface> */
    private array $fixers = [];
    public function registerBuiltIn(): self
    {
        static $builtIn = null;
        if (null === $builtIn) {
            /** @var list<class-string<FixerInterface>> */
            $builtIn = [];
            foreach (['A', 'B'] as $b) {
                $builtIn[] = 'App\Fixer\\' . ('' !== '' ? 'x\\' : '') . $b . 'Fixer';
            }
        }
        foreach ($builtIn as $class) {
            /** @var FixerInterface */
            $fixer = new $class();
            $this->fixers[$fixer->getName()] = $fixer;
        }
        return $this;
    }
    public function names(): array { return \array_keys($this->fixers); }
}
$f = new Factory();
echo \implode(',', $f->registerBuiltIn()->names()), "\n";
echo \implode(',', (new Factory())->registerBuiltIn()->names()), "\n";
