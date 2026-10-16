<?php
// A bare `array` property's last value and an `(array)$object` result are released (FlattenException / VarDumper shapes).

class Flat
{
    private array $trace = [];
    public function setTrace(array $trace): static
    {
        $this->trace = [];
        foreach ($trace as $entry) {
            $this->trace[] = ['class' => $entry['class'] ?? '', 'function' => $entry['function'] ?? null, 'args' => []];
        }
        return $this;
    }
    public function rows(): int { return count($this->trace); }
}
class Plain { public array $any = []; protected string $s = 'x'; }
final class Bag { }

function cast(object $o): array { return (array) $o; }

function churn(int $n): int
{
    $trace = [['class' => 'A\B', 'function' => 'f'], ['function' => 'g'], ['class' => 'C', 'function' => 'h']];
    $p = new Plain();
    $p->any = ['k' . $n, [1, 2]];
    $e = new RuntimeException('x');
    $sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $f = new Flat();
        $sum += $f->setTrace($trace)->rows();
        $sum += count((array) $p) + count(cast($p)) + (count(cast($e)) > 0 ? 1 : 0) + count((array) new Bag());
    }
    return $sum;
}

echo churn(2000), "\n";
$before = memory_get_peak_usage();
churn(100000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
