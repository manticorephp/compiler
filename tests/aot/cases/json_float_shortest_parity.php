<?php
// json_encode's float text against php over many doubles: short decimals (the
// exact fast path), thirds and sums (Ryu), random bit patterns, both signs and
// the edges of the fast path's range [1e-5, 1e15).
mt_srand(7);
$vals = [0.25, 1.5, 100.0, 1e15, 9.99999999999999e14, 1e-5, 9.99e-6, 0.1 + 0.2, 1/3, 2/3, 123456.789, 5e-324, 1.7976931348623157e308, 0.3, 4.35, 1.005, 0.07];
for ($i = 0; $i < 20000; $i++) {
    $vals[] = $i + 0.25;
    $vals[] = $i / 7;
    $vals[] = $i * 0.01;
    $vals[] = round(mt_rand() / mt_getrandmax() * 1000, mt_rand(0, 6));
    $vals[] = mt_rand(1, 999999) * 10 ** mt_rand(-9, 12);
    $vals[] = -($i * 1.1);
    $bits = pack('J', (mt_rand() << 32) ^ mt_rand());
    $f = unpack('E', $bits)[1];
    if (is_finite($f)) { $vals[] = $f; }
}
$s = json_encode($vals);
echo count($vals), " ", strlen($s), " ", md5($s), "\n";
echo json_encode(array_slice($vals, 0, 17)), "\n";
echo json_encode([1.5, 100.0, -0.25], JSON_PRESERVE_ZERO_FRACTION), "\n";
