<?php
foreach (['', 'a', 'ab', 'abc', "é", "aéb", "日本語テキスト", "a\xffb", "x\0y", "😀a😀"] as $s) {
    foreach ([1, 2, 3, 100] as $l) { echo json_encode(array_map('bin2hex', mb_str_split($s, $l))), "\n"; }
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    $s = substr(str_repeat("ab\u{e9}\u{65e5}c", intdiv($n, 3) + 1), 0, $n);
    $s = mb_strcut($s, 0, $n);
    foreach ([1, 3] as $l) {
        $p = mb_str_split($s, $l);
        echo $n, ' ', $l, ' ', count($p), ' ', md5(implode('|', $p)), ' ', implode('', $p) === $s ? 'ok' : 'bad', "\n";
    }
}
try { mb_str_split('abc', 0); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
