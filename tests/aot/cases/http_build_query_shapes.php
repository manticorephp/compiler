<?php
$cases = [
    [], ['a' => 1], ['a' => 1, 'b' => 'x y', 'c' => 'ü&=+'], [1, 2, 3], ['a' => [1, 2, 3]], ['a' => ['b' => ['c' => 'd']]],
    ['n' => null, 'x' => 1], ['t' => true, 'f' => false, 'z' => 0, 'e' => ''], ['fl' => 1.5, 'neg' => -3, 'big' => 12345678901234],
    ['a b' => 'c d', 'e/f' => 'g~h', 'k[]' => 'v'], [5 => 'five', 'k' => 'v'], ["a\0b" => "c\xffd"], ['a' => [], 'b' => 1],
    ['list' => [['x' => 1], ['x' => 2]]], ['x' => ['y' => null, 'z' => 'w']],
];
foreach ($cases as $c) {
    echo http_build_query($c), "|", http_build_query($c, 'p_'), "|", http_build_query($c, '', ';'), "|", http_build_query($c, '', '&amp;'), "|", http_build_query($c, '', null, PHP_QUERY_RFC3986), "\n";
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 20000] as $n) {
    $a = []; for ($i = 0; $i < $n; $i++) { $a["k$i"] = $i % 3 === 0 ? "v $i" : $i; }
    $q = http_build_query($a); echo $n, ' ', strlen($q), ' ', md5($q), "\n";
}
$s = ['a' => 'x']; $q = http_build_query($s); $s['a'] = 'y'; echo $q, http_build_query($s), "\n";
