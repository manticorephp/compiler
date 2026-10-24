<?php
// Overrides php accepts: they must compile and behave like php.
namespace App\Models;

interface Shape { public function area(): float; public function scale(int|float $k): static; }
interface Named { public function name(): string; }
interface Cloner { public function copy(): self; }

class Animal {}
class Dog extends Animal {}
class Puppy extends Dog {}

abstract class Base implements Named {
    public function __construct(protected int $id = 0) {}
    abstract public function make(string $s, int ...$rest): ?Animal;
    public function pick(Dog $d): Animal { return $d; }
    public function maybe(?int $x = null, array $y = [], $z = 'a long default string'): iterable { return []; }
    public function name(): string { return 'base'; }
    public function fluent(): static { return $this; }
    public function same(): self { return $this; }
    public function untyped($a, $b = 1) { return $a; }
    public function &refRet(array &$a): array { return $a; }
    public function byRef(array &$a): void { $a[] = 1; }
    public function nev(): never { throw new \Exception('x'); }
    public function mixedRet(): mixed { return 1; }
    public function unionRet(): int|string|null { return 1; }
    public function boolRet(): bool { return true; }
    public function cb(callable $c): callable { return $c; }
    public function obj(object $o): object { return $o; }
    private function hidden(int $a): int { return $a; }
}

class Impl extends Base implements Shape, Cloner {
    public function __construct(string $other = 'x', int $more = 1) { parent::__construct(1); }
    public function make(string|int $s, int|string ...$rest): Puppy { return new Puppy; }
    public function pick(Animal $d, int $extra = 0, string ...$more): Dog { return new Dog; }
    public function maybe(int|string|null $x = null, iterable $y = [], mixed $z = null, int $w = 5): array { return []; }
    public function name(): string { return 'impl'; }
    public function fluent(): static { return new static(); }
    public function same(): static { return $this; }
    public function untyped($a = 0, $b = 1, $c = 2) { return $a; }
    public function &refRet(array &$a): array { return $a; }
    public function byRef(array &$a): void { $a[] = 2; }
    public function nev(): never { throw new \Exception('y'); }
    public function mixedRet(): int { return 2; }
    public function unionRet(): int { return 3; }
    public function boolRet(): true { return true; }
    public function cb(callable|string $c): \Closure { return fn() => 1; }
    public function obj(mixed $o): self { return $this; }
    private function hidden(string $a, int $b): string { return $a; }
    public function area(): float { return 1.5; }
    public function scale(int|float|string $k): static { return $this; }
    public function copy(): static { return clone $this; }
}

trait Greets { public function hi(string $n): string { return "hi $n"; } }
interface Greeter { public function hi(string $n): string; }
class G implements Greeter { use Greets; }
class G2 extends G { public function hi(string|int $n, string $suffix = '!'): string { return "yo $n$suffix"; } }

enum Level: int implements Named {
    case Low = 1;
    case High = 2;
    public function name(): string { return $this->name; }
}

abstract class Gen {
    /** @param array<int, string> $items */
    public function each(array $items): iterable { return $items; }
    public function __toString(): string { return 'gen'; }
}
class Gen2 extends Gen implements \Stringable {
    /** @param list<int> $items */
    public function each(array $items): array { return $items; }
}

class Ctor1 { public function __construct(int $a) {} }
class Ctor2 extends Ctor1 { public function __construct(string $a, string $b) {} }

class Prop1 { public ?int $p = null; public readonly int $r; public static int $s = 1; protected array $arr = []; public function __construct() { $this->r = 1; } }
class Prop2 extends Prop1 { public int|null $p = 1; public static int $s = 2; protected array $arr = [1]; }

class Konst1 { const int A = 1; const string|int B = 'b'; const C = 3; }
class Konst2 extends Konst1 { const int A = 2; const int B = 5; const int C = 4; }

$anon = new class extends Dog implements Named {
    public function name(): string { return 'anon'; }
};

$i = new Impl();
echo get_class($i->make('a', 1, 2)), "\n";
echo get_class($i->pick(new Animal)), "\n";
echo $i->name(), ' ', $anon->name(), ' ', Level::High->name(), "\n";
echo (new G2)->hi('bob'), ' ', (new G)->hi('al'), "\n";
echo $i->area(), ' ', get_class($i->scale(2)), ' ', get_class($i->copy()), "\n";
$a = [];
$i->byRef($a);
echo count($a), "\n";
echo Prop2::$s, ' ', (new Prop2)->p, ' ', Konst2::B, "\n";
echo implode(',', (new Gen2)->each([1, 2])), ' ', new Gen2, "\n";

class PrivP { private int $p = 1; private function f(int $a) { return 1; } }
class PrivC extends PrivP { public string $p = 'a'; public function f(string $a, $b = 0) { return 2; } }
$pc = new PrivC;
echo $pc->p, ' ', $pc->f('x'), "\n";
