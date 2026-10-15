<?php
// printf %e / %f of NAN and INF prints lowercase nan/inf; php prints NaN and INF.
printf("%e %e %e %f %F %g\n", NAN, INF, -INF, NAN, INF, NAN);
foreach (['%+f', '%8f|', '%-8f|', '%08f', "%'x8f", '%.2f', '%+e', '%5.1e', '%G'] as $f) {
    echo $f, ' => [', sprintf($f, NAN), '] [', sprintf($f, INF), '] [', sprintf($f, -INF), "]\n";
}
echo number_format(INF), ' ', round(INF), ' ', INF, ' ', -INF, "\n";
