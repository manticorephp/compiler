<?php
// The clocks are non-deterministic by nature, so assert their SHAPE and their
// relations, never a value.
$t = time();
// Sanity window: after 2020-01-01 and before 2100-01-01.
echo ($t > 1577836800 && $t < 4102444800) ? "time-sane\n" : "time-insane\n";

// microtime(true) is float seconds, and agrees with time() to the second.
$mf = microtime(true);
echo is_float($mf) ? "mf-float\n" : "mf-notfloat\n";
echo (abs($mf - (float)$t) < 2.0) ? "mf-agrees\n" : "mf-drifts\n";

// The default microtime() is the "0.86688700 1720000000" string: an 8-place
// fraction, a space, then the whole seconds.
$ms = microtime();
$parts = explode(" ", $ms);
echo count($parts), "\n";
echo (strlen($parts[0]) === 10 && substr($parts[0], 0, 2) === "0.") ? "ms-frac\n" : "ms-badfrac\n";
echo ((int)$parts[1] >= $t) ? "ms-secs\n" : "ms-badsecs\n";

// hrtime(true) is int nanoseconds off the MONOTONIC clock: never goes backwards.
$a = hrtime(true);
$b = hrtime(true);
echo is_int($a) ? "hr-int\n" : "hr-notint\n";
echo ($b >= $a) ? "hr-monotonic\n" : "hr-backwards\n";

// The default hrtime() is the [seconds, nanoseconds] pair.
$pair = hrtime();
echo count($pair), "\n";
echo ($pair[1] >= 0 && $pair[1] < 1000000000) ? "hr-nsrange\n" : "hr-nsoverflow\n";

// $_SERVER carries the request time, stamped at process start.
echo (abs($_SERVER['REQUEST_TIME'] - $t) < 2) ? "req-time\n" : "req-drifts\n";
echo is_float($_SERVER['REQUEST_TIME_FLOAT']) ? "req-float\n" : "req-notfloat\n";
