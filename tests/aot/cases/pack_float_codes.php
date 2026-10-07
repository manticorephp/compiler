<?php
// pack()/unpack() float codes f, g, G, d, e, E
echo bin2hex(pack('g', 0.1)), ' ', bin2hex(pack('G', 0.1)), "\n";
echo bin2hex(pack('e', 0.1)), ' ', bin2hex(pack('E', 0.1)), "\n";
var_dump(unpack('g', pack('g', 0.1))[1]);
var_dump(unpack('E', pack('E', -2.5))[1]);
var_dump(unpack('e2', pack('e2', 1.5, 3.25)));
foreach ([0.0, -0.0, 1.0, -1.5, 0.1, 16777217.0, 3.4028234663852886e38, 1e39, -1e39, INF, -INF, 1.401298464324817e-45, 7e-46, 1e-320, 4.9e-324, PHP_FLOAT_MAX, PHP_FLOAT_MIN] as $v) {
    echo bin2hex(pack('g', $v)), ' ', bin2hex(pack('E', $v)), ' ';
    var_dump(unpack('g', pack('g', $v))[1], unpack('d', pack('d', $v))[1] === $v);
}
var_dump(is_nan(unpack('G', pack('G', NAN))[1]), is_nan(unpack('e', pack('e', NAN))[1]));
echo bin2hex(pack('f', 3)), ' ', bin2hex(pack('e', '2.5')), ' ', bin2hex(pack('g', true)), "\n";
var_dump(unpack('Cid/e2pt/gz/Nn', pack('Ce2gN', 7, 1.25, -8.5, 0.75, 99)));
var_dump(unpack('g*', pack('g*', 1.0, 2.0, 3.5)));
echo strlen(pack('d3', 1.0, 2.0, 3.0)), ' ', strlen(pack('f2x', 1.0, 2.0)), "\n";
