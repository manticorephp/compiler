<?php
// superset: no php oracle — a #[TypeDef] element is an object under Zend; expected output is written by hand
use Manticore\Ds\UInt16Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\Int32Array;

#[TypeDef(repr: 'u16')]
final class Kind
{
    public function __construct(public readonly int $value) {}
    public function isComment(): bool { return $this->value === 7 || $this->value === 8; }
    public function next(): Kind { return new Kind($this->value + 1); }
}
#[TypeDef(repr: 'f64')]
final class Meters
{
    public function __construct(public readonly float $value) {}
    public function km(): float { return $this->value / 1000.0; }
}

final class Toks
{
    /** @var UInt16Array<Kind> */
    public UInt16Array $kinds;
    public function __construct(int $n)
    {
        /** @var UInt16Array<Kind> $k */
        $k = new UInt16Array($n);
        $this->kinds = $k;
    }
    public function comments(): int
    {
        $s = 0;
        $n = count($this->kinds);
        for ($i = 0; $i < $n; $i++) { if ($this->kinds[$i]->isComment()) { $s++; } }
        return $s;
    }
}
/** @param UInt16Array<Kind> $k */
function countC(UInt16Array $k, int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { if ($k[$i]->isComment()) { $s++; } } return $s; }
function attempt(callable $f): void { try { $f(); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }

/** @var UInt16Array<Kind> $k */
$k = new UInt16Array(3);
$k[0] = new Kind(7);
$k[2] = (new Kind(7))->next();
echo $k[0]->isComment() ? 'y' : 'n', $k[1]->isComment() ? 'y' : 'n', $k[2]->isComment() ? 'y' : 'n', ' ', $k[2]->value, "\n";
$e = $k[2];
echo $e->next()->value, "\n";
$k[] = new Kind(8);
$k->push(new Kind(1));
echo count($k), ' ', $k->pop()->value, ' ';
$p = $k->pop();
echo $p->isComment() ? 'y' : 'n', ' ', count($k), ' ', $k->sum(), ' ', $k->max(), "\n";
echo get_class($k), ' ', $k instanceof UInt16Array ? 'is' : 'not', ' ', json_encode($k), "\n";
attempt(function () use ($k) { $k[9] = new Kind(1); });
attempt(function () use ($k) { echo $k[9]->value; });

$t = new Toks(1000);
for ($i = 0; $i < 1000; $i++) { $t->kinds[$i] = new Kind($i % 10); }
echo $t->comments(), ' ', countC($t->kinds, 1000), "\n";

/** @var Float64Array<Meters> $d */
$d = new Float64Array(2);
$d[0] = new Meters(1500.0);
$d[] = new Meters(250.0);
echo $d[0]->km(), ' ', $d->pop()->km(), ' ', count($d), "\n";

// an unbound array is unchanged
$plain = new Int32Array(2);
$plain[] = 9;
echo $plain->pop() + 1, ' ', $plain[0] + 5, ' ', get_class($plain), "\n";
