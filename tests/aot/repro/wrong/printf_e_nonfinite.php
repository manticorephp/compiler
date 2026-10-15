<?php
// printf %e / %f of NAN and INF prints lowercase nan/inf; php prints NaN and INF.
// issue: #266
printf("%e %e %e %f %F %g\n", NAN, INF, -INF, NAN, INF, NAN);
