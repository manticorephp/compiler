<?php
// `$a ??= <mixed>` on a `?array` param: an omitted argument is null (not int 0), and a mutated param is copied without crashing.
class Input
{
    private array $tokens;
    public function __construct(?array $argv = null)
    {
        $argv ??= $_SERVER['argv'] ?? [];
        array_shift($argv);
        $this->tokens = $argv;
    }
    public function count(): int { return count($this->tokens); }
}
function fromServer(?array $a = null): int { $a ??= $_SERVER['argv']; return count($a); }
function fromLiteral(?array $a = null): int { $a ??= [7, 8, 9]; return count($a); }
function keep(?array $a = null): int { $a ??= $_SERVER['argv']; return count($a); }
$n = count($_SERVER['argv']);
echo fromServer() === $n ? "ok" : "bad", fromLiteral(), keep(['x']), "\n";
echo (new Input())->count() === $n - 1 ? "ok" : "bad", (new Input(['a', 'b', 'c']))->count(), "\n";
