<?php
var_dump(hexdec("ff"), hexdec("1a2B"));
var_dump(octdec("777"), bindec("101010"));
echo decbin(42), " ", decoct(64), " ", base_convert("ff", 16, 2), "\n";
var_dump(is_nan(sqrt(-1.0)), is_nan(1.0), is_infinite(1.0), is_finite(2.5));
var_dump(boolval(0), boolval("x"), boolval([]));
echo strval(42), "|", strval(1.5), "\n";
var_dump(is_scalar(1), is_scalar("s"), is_scalar([1]), is_scalar(null));
var_dump(is_iterable([1, 2]), is_iterable(5), is_countable([]));
