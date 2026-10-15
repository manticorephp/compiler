<?php
// Non-finite floats through the runtime format engine (a non-literal format). A literal
// format compiles to an inline C snprintf — faster — and prints nan / inf there.
$fmts = ['%e %e %e %f %F %g', '%+f %8f| %-8f| %08f', "%'x8f %.2f %+e %5.1e %G"];
foreach ($fmts as $f) {
    $n = substr_count($f, '%');
    foreach ([NAN, INF, -INF] as $v) {
        echo vsprintf($f, array_fill(0, $n, $v)), "\n";
    }
}
echo number_format(INF), ' ', round(INF), ' ', INF, ' ', -INF, "\n";
