<?php
foreach ([0.0, 1e-10, 0.5, 1.0, 2.0, 10.0, -0.5] as $x) {
    printf("%s: log1p=%.12e expm1=%.12e asinh=%.12e\n", $x, log1p($x), expm1($x), asinh($x));
}
foreach ([1.0, 1.5, 10.0, 1e10] as $x) {
    printf("acosh(%s)=%.12e\n", $x, acosh($x));
}
foreach ([0.0, 0.5, -0.5, 0.999] as $x) {
    printf("atanh(%s)=%.12e\n", $x, atanh($x));
}
var_dump(is_nan(acosh(0.5)), is_nan(atanh(2.0)), atanh(1.0), atanh(-1.0), log1p(-1.0), expm1(-INF));
var_dump(log1p(-3.0), expm1(1e300), asinh(-3.0), asinh(1e300));
