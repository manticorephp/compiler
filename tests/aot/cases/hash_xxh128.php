<?php
$s = '';
for ($i = 0; $i < 3000; $i++) {
    $s .= chr(($i * 131 + 7) & 255);
}
foreach ([0, 1, 3, 4, 8, 9, 16, 17, 100, 128, 129, 240, 241, 1024, 1025, 3000] as $n) {
    echo $n, ' ', hash('xxh128', substr($s, 0, $n)), "\n";
}
echo bin2hex(hash('xxh128', 'abc', true)), "\n";
echo in_array('xxh128', hash_algos(), true) ? "listed\n" : "missing\n";
