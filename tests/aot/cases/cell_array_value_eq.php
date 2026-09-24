<?php
// A cell (erased element) compared with a typed array: by value, decoding each
// element by its buffer hint (php-cs-fixer FinalInternalClassFixer default config).
final class F
{
    /** @var array<string, mixed> */
    private array $configuration = [];
    public function __construct() { $this->configuration = ['include' => ['internal' => true], 'x' => 1]; }
    public function check(): array
    {
        $defaults = [];
        foreach (['@internal'] as $foo) { $defaults[strtolower(ltrim($foo, '@'))] = true; }
        $c = $this->configuration['include'];
        return [
            $this->configuration['include'] !== $defaults,
            $this->configuration['include'] === $defaults,
            $this->configuration['include'] == $defaults,
            $c === $defaults,
            $defaults === ['internal' => true],
            ['a', 'b'] === ['a', 'b'],
            ['a' => 1] == ['a' => 1.0],
            ['a' => 1] === ['a' => 1.0],
            $this->configuration['x'] === 1,
        ];
    }
}
var_dump((new F())->check());
function g($a, $b) { return [$a === $b, $a == $b, $a !== $b]; }
var_dump(g([1, [2]], [1, [2]]), g(['k' => 'v'], ['k' => 'w']));
