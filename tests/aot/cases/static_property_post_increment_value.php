<?php
// `self::$counter++` used as an expression yields the incremented value instead of the old one
class C
{
    private static int $counter = 0;

    public function next(): int
    {
        return self::$counter++;
    }

    public function label(string $name): string
    {
        return $name . '_' . self::$counter++;
    }
}

$c = new C();
echo $c->next(), $c->next(), ' ', $c->label('a'), ' ', $c->label('a'), "\n";

class D
{
    public static int $n = 5;
    public int $p = 5;
    public array $a = ['k' => 5];
    public static function s(): string { return static::$n++ . ',' . static::$n . ',' . D::$n-- . ',' . ++self::$n . ',' . --D::$n; }
    public function i(): string { return $this->p++ . ',' . $this->p . ',' . $this->p-- . ',' . ++$this->p; }
    public function e(): string { return $this->a['k']++ . ',' . $this->a['k'] . ',' . $this->a['k']-- . ',' . --$this->a['k']; }
}
echo D::s(), "\n", (new D())->i(), "\n", (new D())->e(), "\n";
$d = new D();
$d->p++;
D::$n++;
$d->a['k']++;
for ($i = 0; $i < 2; D::$n++, $d->p++) { $i++; }
echo D::$n, ' ', $d->p, ' ', $d->a['k'], "\n";
function f(int $x): int { return $x * 10; }
echo f(D::$n++), ' ', D::$n, "\n";
