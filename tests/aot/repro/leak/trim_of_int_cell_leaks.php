<?php
// A non-builtin string function (trim) applied to an int held in a cell leaks the rendered string on every call.
// issue: #128
function mv(int $i): mixed { return $i % 2 === 0 ? $i * 1000 : "s$i"; }
$m0 = memory_get_peak_usage();
$t = 0;
for ($i = 0; $i < 300000; $i++) { $t += strlen(trim(mv($i * 2 + 2))); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 4 ? "flat\n" : "LEAK\n";
