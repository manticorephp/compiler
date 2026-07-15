<?php
// echo / (string) / concat of a float uses PHP precision=14, and its scientific
// form is "1.0E+20" — uppercase E, a ".0" mantissa, and no leading zero on the
// exponent — where C's "%g" gives "1e+20"/"1e-05". The decimal/scientific
// threshold matches C, so only the scientific rendering is rewritten.
$vals = [
    9.5, 1.0 / 3.0, 0.1, 3.14159265358979, 1.0e20, 1.0e-5, 100.0,
    1234567890123.0, 1.7976931348623157e308, 2.5, 0.0001, 1.0e16, 1.0e15,
    123456789.12345, 9.0e14, 1.5e-4, -9.5, -1.0e20, 5.0e-324, 0.0, -0.0,
];
foreach ($vals as $v) {
    echo $v, "\n";                 // direct echo
    echo (string)$v, "\n";         // explicit cast
    echo "[" . $v . "]\n";         // concat coercion
}
// through a cell (mixed array value)
$mixed = [1.0e17, 2.0 / 3.0, 42, "s"];
foreach ($mixed as $m) {
    echo $m, "\n";
}
