<?php
// Dead objects the cycle collector buffered must not ratchet its threshold up: their shells have to be freed and counted.
function mk3(int $d): \Throwable { return $d > 0 ? mk3($d - 1) : new \RuntimeException('a', 0, new \RuntimeException('p')); }
class H { public $x; }
function t(): int { $e = mk3(3); $h = new H; $h->x = $e; $g = $e; unset($e); $arr = [$h, $g]; return count($arr); }
function churn(int $n): void { for ($i = 0; $i < $n; $i++) { t(); } }
if (getenv("NEVER_SET")) { gc_collect_cycles(); }
churn(5000);
$b = memory_get_usage();
churn(100000);
echo (memory_get_usage() - $b) < 1000000 ? "flat" : "leak", "\n";
