<?php
// Echo-form var_export($x) inside ob_start()/ob_end_clean() leaks memory on every call.
// issue: #129
$m0 = memory_get_peak_usage();
for ($r = 0; $r < 2000; $r++) {
    for ($i = 0; $i < 1000; $i++) {
        ob_start();
        var_export("value $i");
        ob_end_clean();
    }
}
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";
