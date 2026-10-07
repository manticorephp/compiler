<?php
use Manticore\Ds\Int16Array;
final class Money implements JsonSerializable {
    public function __construct(private int $cents, public string $cur) {}
    public function jsonSerialize(): mixed { return ['amount' => $this->cents / 100, 'currency' => $this->cur]; }
}
abstract class Base implements JsonSerializable { public int $id = 3; public function jsonSerialize(): array { return ['id' => $this->id, 'kind' => static::class]; } }
final class Kid extends Base {}
final class Selfish implements JsonSerializable { public int $x = 1; public function jsonSerialize(): mixed { return $this; } }
final class Nested implements JsonSerializable { public function jsonSerialize(): mixed { return [new Money(5, 'USD'), new Kid()]; } }
final class HasMethodOnly { public int $p = 9; public function jsonSerialize(): mixed { return 'never'; } }
final class Scalar implements JsonSerializable { public function jsonSerialize(): mixed { return 42; } }
final class Plain { public int $a = 1; public ?Money $m = null; }
$p = new Plain(); $p->m = new Money(1999, 'EUR');
echo json_encode(new Money(1250, 'EUR')), "\n";
echo json_encode(new Kid()), "\n";
echo json_encode(new Selfish()), "\n";
echo json_encode(new Nested()), "\n";
echo json_encode(new HasMethodOnly()), "\n";
echo json_encode([new Scalar(), 'k' => new Scalar()]), "\n";
echo json_encode($p), "\n";
echo json_encode(new Money(100, 'UAH'), JSON_PRETTY_PRINT), "\n";
echo json_encode(['list' => [new Kid(), new Scalar()]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
$a = Int16Array::fromArray([1, -2, 300]);
echo json_encode($a), ' ', json_encode(['k' => $a]), ' ', json_encode($a, JSON_PRETTY_PRINT), "\n";
function enc(mixed $v): string { return json_encode($v); }
echo enc(new Money(1, 'X')), enc($a), "\n";
for ($i = 0; $i < 200000; $i++) { $s = json_encode(new Money($i, 'EUR')); }
echo memory_get_peak_usage() < 64 * 1024 * 1024 ? "flat\n" : "LEAK\n";
