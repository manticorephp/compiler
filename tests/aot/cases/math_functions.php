<?php
// Elementary float math builtins: trig (sin/cos/tan + inverse), hyperbolic,
// exp/log family, hypot/atan2, pi/deg2rad/rad2deg. LLVM intrinsics where they
// exist (sin/cos/exp/log/log10), plain libm calls otherwise.
printf("%.6f %.6f %.6f\n", sin(1.0), cos(1.0), tan(1.0));
printf("%.6f %.6f %.6f\n", asin(0.5), acos(0.5), atan(1.0));
printf("%.6f %.6f\n", atan2(1.0, 2.0), hypot(3.0, 4.0));
printf("%.6f %.6f %.6f\n", sinh(1.0), cosh(1.0), tanh(1.0));
printf("%.6f %.6f %.6f %.6f\n", exp(1.0), log(M_E), log(8.0, 2.0), log10(1000.0));
printf("%.6f %.6f %.6f\n", pi(), deg2rad(180.0), rad2deg(M_PI));
