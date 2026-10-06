<?php
// A local holding an object on one path and an untyped call result (Resource|false) on
// another: every overwrite of the object leaked it. @serial: a memory measurement.
final class Big { public string $pool; public function __construct() { $this->pool = str_repeat('x', 4096); } }
/** @return \Resource|false */
function mixedf() { return $GLOBALS["argc"] > 100 ? STDERR : false; }
function run(int $n): void { for ($i = 0; $i < $n; $i++) { if ($i % 2 === 0) { $c = new Big(); } else { $c = mixedf(); } } }
run(2000); $b = memory_get_usage(); run(40000); $d = memory_get_usage() - $b;
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";
