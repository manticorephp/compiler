<?php
// A null passed to a non-nullable int parameter is coerced to 0 instead of throwing TypeError.
// issue: #172
declare(strict_types=1);

function f(int $x): int { return $x + 1; }

final class K
{
    public function has(int $id): bool { return $id > 0; }
}

$v = [null, 5][0];

try { var_dump(f($v)); } catch (\TypeError $e) { echo "TypeError fn\n"; }
try { var_dump((new K)->has($v)); } catch (\TypeError $e) { echo "TypeError method\n"; }

$g = static fn (?int $t) => f($t);
try { var_dump($g(null)); } catch (\TypeError $e) { echo "TypeError via ?int\n"; }
