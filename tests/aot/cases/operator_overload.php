<?php
// Superset: a class opts into operators via Manticore\Overloadable (no Zend oracle).
final class Money implements \Manticore\Overloadable
{
    public function __construct(public readonly int $cents) {}

    public function __operator(string $op, mixed $other, bool $swapped): static
    {
        $o = $other instanceof Money ? $other->cents : (int) ($other * 100);
        $a = $swapped ? $o : $this->cents;
        $b = $swapped ? $this->cents : $o;
        return new Money(match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => intdiv($a * $b, 100),
            '/' => intdiv($a * 100, $b),
            '%' => $a % $b,
            '**' => $a ** (int) ($b / 100),
        });
    }

    public function __compare(mixed $other): int
    {
        $o = $other instanceof Money ? $other->cents : (int) ($other * 100);
        return $this->cents <=> $o;
    }

    public function __toString(): string
    {
        return sprintf('%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}

$a = new Money(1050);
$b = new Money(250);
echo $a + $b, "\n", $a - $b, "\n", $a * 2, "\n", 20 - $a, "\n", $a / $b, "\n", $a % $b, "\n", $b ** 2, "\n";
var_dump($a > $b, $a < $b, $a == new Money(1050), $a != $b, 5 < $a, 11 >= $a, $a <=> $b, 3 <=> $a, $a === $a);
$c = $a;
$c += $b;
$c *= 2;
echo $c, " ", $a, "\n";
echo ($a + $b) * 2 - 1, "\n";
