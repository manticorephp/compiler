<?php
$ws = ['', 'a', 'b', 'ab', 'ba', 'abc', 'kitten', 'sitting', 'flaw', 'lawn', 'saturday', 'sunday', "a\0b", "\xff\xfe", 'ünï', 'same', 'same'];
foreach ($ws as $a) { $row = []; foreach ($ws as $b) { $row[] = levenshtein($a, $b); } echo implode(' ', $row), "\n"; }
foreach ([[1, 1, 1], [2, 3, 4], [3, 1, 2], [0, 0, 0], [1, 5, 1], [5, 1, 5], [1, 1, 10]] as [$i, $r, $d]) {
    foreach ([['kitten', 'sitting'], ['', 'abc'], ['abc', ''], ['abcdef', 'azced'], ['a', 'b'], ['same', 'same']] as [$a, $b]) { echo levenshtein($a, $b, $i, $r, $d), ' '; }
    echo "\n";
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 300] as $n) {
    $a = substr(str_repeat('abcdefg', intdiv($n, 7) + 1), 0, $n); $b = substr(str_repeat('badcfeg', intdiv($n, 7) + 2), 3, $n);
    echo $n, ' ', levenshtein($a, $b), ' ', levenshtein($a, $b, 2, 3, 1), ' ', levenshtein($a, ''), ' ', levenshtein('', $b, 3, 1, 1), "\n";
}
echo levenshtein(str_repeat('a', 2000), str_repeat('b', 2000)), "\n";
