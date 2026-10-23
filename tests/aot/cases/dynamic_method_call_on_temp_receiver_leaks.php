<?php
// A dynamic method call on a fresh temporary receiver (`(new F)->$m()`) never releases the object.
class F { private array $t = []; function s(): void { $this->t = [['x' => 'y' . mt_rand()]]; } }
function churn(int $n): void { $m = 's'; for ($i = 0; $i < $n; $i++) { (new F)->$m(); } }
churn(2000);
$before = memory_get_peak_usage();
churn(200000);
echo memory_get_peak_usage() - $before < 2 << 20 ? "flat\n" : "grows\n";
