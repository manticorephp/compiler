<?php
use A\B as C, D\E;
use F\{G, H as I};
#[Attr(1), Other]
final class Foo extends Bar implements Baz
{
    use T1, T2 { T1::hi insteadof T2; T2::hi as protected hey; }
    const A = 1, B = 2;
    #[Pr] public int $x = 0, $y = 1;
    public string $name { get => $this->name; set(string $v) { $this->name = $v; } }
    public function __construct(#[Sens] private readonly int $id = 5, public ?string &$ref = null, int ...$rest) {}
}
abstract class Ab { abstract protected function run(): void; }
interface I1 { const C = 'c'; public function f(int $a): int; }
enum Suit: string { case H = 'h'; #[Lbl] case S = 's'; }
$o = new class(1) extends Bar { public int $z = 2; };
$f = function (int $a) use ($o, &$b): int { return $a; };
function fn1(int $a = 1, array &$b = [], string ...$c): void {}
