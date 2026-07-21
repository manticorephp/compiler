<?php
// Float formatting parity torture for the native json double emitter:
// fixed/scientific boundary (eSci -4 / 16), integral floats, dirty shortest
// 17-digit doubles, subnormals, negative zero, huge/tiny magnitudes.
$vals = [
    0.1, 0.2, 0.30000000000000004, 9.5, 2.5, -2.5, 3.0, -3.0,
    1e15, 1e16, 1e17, 1.25e16, 123456789.0, 1e20, -1e20,
    0.0001, 0.00001, 1e-5, 1.5e-5, -0.0001,
    5e-324, 2.2250738585072014e-308, 1.7976931348623157e308,
    -0.0, 0.0, 1.0e15 + 1.0, 1.0e15 - 1.0,
    0.5, 0.25, 0.125, 1.0 / 3.0, 2.0 / 3.0,
    100.0, 1000000.0, 9007199254740992.0, 9007199254740993.0,
    3.141592653589793, 2.718281828459045, 1.1, 2.2, 3.3,
];
foreach ($vals as $v) {
    echo json_encode($v), "\n";
}
echo json_encode([1.5, -0.75, ["x" => 6.02e23, "y" => -1.6e-19]]), "\n";
