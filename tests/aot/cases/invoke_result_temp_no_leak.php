<?php
// Fresh temps are released: a discarded or type-tested invoke result, gettype() of a call, a closure literal passed to a mixed param (set_error_handler), a popped closure.

class K { public function m(): mixed { return 'm' . mt_rand(); } }

function discarded(\Closure $f, int $n): void { for ($i = 0; $i < $n; $i++) { $f(); } }
function viaCallable(callable $f, int $n): void { for ($i = 0; $i < $n; $i++) { $f(); } }
function predicate(\Closure $f, int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += \is_string($f()) ? 1 : 0; } return $s; }
function typeName(K $k, int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s += \gettype($k->m()) === 'string' ? 1 : 0; } return $s; }
function handlers(int $n): void { for ($i = 0; $i < $n; $i++) { set_error_handler(static fn (int $t, string $m): bool => false); restore_error_handler(); } }
function popped(int $n): void { $l = []; for ($i = 0; $i < $n; $i++) { $l[] = static fn () => $i; \array_pop($l); } }

function churn(int $n): int
{
    $obj = fn () => new \RuntimeException('x' . mt_rand());
    $str = fn () => 's' . mt_rand();
    discarded($obj, $n);
    discarded($str, $n);
    viaCallable($obj, $n);
    handlers($n);
    popped($n);
    return predicate($str, $n) + typeName(new K(), $n);
}

echo churn(2000), "\n";
$before = memory_get_peak_usage();
churn(100000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
