<?php
// @serial: a memory measurement.
final class E extends \Exception { public string $pad; public function __construct(int $i) { parent::__construct('e' . $i); $this->pad = str_repeat('c', 512); } }
function thr(int $i): void { throw new E($i); }
function once(int $i): int {
    try { thr($i); } catch (E $e) { $n = strlen($e->getMessage()); if ($i % 7 === 0) { try { throw $e; } catch (E $again) { $n++; } } return $n; }
    return 0;
}
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += once($i); }
$b = memory_get_usage();
for ($i = 0; $i < 40000; $i++) { $sum += once($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
