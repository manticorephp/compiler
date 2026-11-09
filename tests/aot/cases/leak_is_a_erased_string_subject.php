<?php
// is_a() over an erased class-name subject with allow_string releases the string it unboxes (Laravel Container::build).
interface Svc {}
class Impl implements Svc {}
function check($concrete): int { $c = $concrete; return is_a($c, Svc::class, true) ? 1 : 0; }
$names = [];
for ($i = 0; $i < 4; $i++) { $names[] = 'Imp' . 'l'; $names[] = str_repeat('NotAClass', 20) . $i; }
$k = 0;
foreach ($names as $n) { $k += check($n); }
echo $k, ' ', check(new Impl()), "\n";
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 50000; $i++) { foreach ($names as $n) { $k += check(strtolower($n)); } }
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
