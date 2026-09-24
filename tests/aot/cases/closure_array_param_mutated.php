<?php
interface Named { public function getName(): string; }
final class F implements Named { public function __construct(private string $n) {} public function getName(): string { return $this->n; } }
final class Runner {
    /** @var array<string, Named> */
    private array $byName;
    /** @param list<Named> $fixers */
    public function __construct(array $fixers) {
        $this->byName = array_reduce(
            $fixers,
            static function (array $carry, Named $fixer): array {
                $carry[$fixer->getName()] = $fixer;
                return $carry;
            },
            [],
        );
    }
    public function names(): string { return implode(',', array_keys($this->byName)); }
}
echo (new Runner([new F('a'), new F('b'), new F('c')]))->names(), "\n";
echo array_reduce([1, 2, 3], static fn (int $c, int $v): int => $c + $v, 0), "\n";
