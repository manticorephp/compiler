<?php
// json_encode float — shortest round-trip (Ryu), byte-exact with php's
// serialize_precision=-1. The `%.14g` path this replaced was WRONG on every
// value needing >14 significant digits, and on the scientific-notation form.
$vals = [
    9.5, 0.1, 0.3, 1.0 / 3.0, 2.0 / 3.0, 3.14159265358979,
    1.1, 100.0, 0.0001, 1.0e20, 1.0e-5, 123456789.123456789,
    1234567890123.0, 0.7, 2.5, -9.5, -0.0, 0.0,
    1.0e16, 1.0e17, 5.0e-324, 1.7976931348623157e308,
    12345678901234567.0, 9999000000000000.0,
];
foreach ($vals as $v) {
    echo json_encode($v), "\n";
}
// nested in a structure (the encoder's array/object float path)
echo json_encode(["a" => 1.5, "b" => [0.1, 0.2, 0.3], "c" => 1.0e21]), "\n";
