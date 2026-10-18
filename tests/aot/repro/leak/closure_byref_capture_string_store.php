<?php
// A store into a by-reference closure capture leaks a string per store.
// issue: #348
function app(string &$t, string $x): void { $f = function () use (&$t, $x) { $t = str_repeat($x, 30); }; $f(); }
function loopit(): void { $s = "s"; for ($i = 0; $i < 1000; $i++) { app($s, "x"); } }
loopit();
$b = memory_get_peak_usage();
for ($j = 0; $j < 200; $j++) { loopit(); }
echo memory_get_peak_usage() - $b < 1024 * 1024 ? "flat\n" : "grew\n";
