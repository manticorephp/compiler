<?php
var_dump(fpow(2.0, 10.0), fpow(2.0, 0.5) === sqrt(2.0), fpow(-8.0, 1.0 / 3.0), fpow(0.0, 0.0));
var_dump(fpow(0.0, -1.0), fpow(-0.0, -1.0), fpow(INF, 0.0), fpow(1.0, NAN), fpow(-2.0, 3.0), fpow(10.0, 308.0), fpow(10.0, 309.0));
