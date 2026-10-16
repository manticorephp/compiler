<?php
// count() over a fresh erased operand (a bare-`array` / `mixed` method result) releases it when a Countable class exists (Request::duplicate attributes).
class C implements \Countable { public function count(): int { return 3; } }
class Bag
{
    public function __construct(protected array $parameters = []) {}
    public function all(): array { return $this->parameters; }
    public function fresh(): array { return ['a' => 'x' . mt_rand(1, 9), 'b' => [1, 2]]; }
    public function mix(): mixed { return ['q' . mt_rand(1, 9)]; }
}
function churn(Bag $b, int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += \count($b->all()) + \count($b->fresh()) + \count($b->mix()) + \count(new C()) + \count($b->fresh(), COUNT_RECURSIVE); } return $s; }
$b = new Bag(['x' => 1, 'y' => 's']);
echo churn($b, 3), "\n";
$before = memory_get_peak_usage();
churn($b, 100000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
