<?php
$l = localeconv();
echo implode(',', array_keys($l)), "\n";
var_dump($l['decimal_point'], $l['thousands_sep'], $l['frac_digits'], $l['grouping']);
foreach ([0, 1] as $t) {
    foreach ([0, 2, 3, 16, 19, 32, 35] as $f) {
        $r = get_html_translation_table($t, $f, "UTF-8");
        echo "$t $f ", count($r), " ", md5(json_encode($r)), "\n";
    }
}
echo json_encode(get_html_translation_table(0, 3)), "\n";
echo json_encode(array_slice(get_html_translation_table(1, 3), 0, 8)), "\n";
$r = get_html_translation_table(1, 3, "ISO-8859-1");
echo count($r), " ", md5(json_encode($r)), "\n";
echo count(get_html_translation_table()), "\n";
foreach (["abc \xe0\xe1\xe2 def 123", "\xe0\xe1 (x) \xe2", "", "a\n\xe0\xe1 b\r\n", "\xe0\xe1\xe2 \xe3\xe4\xe5 abc def ghi \xe6"] as $s) {
    echo bin2hex(hebrev($s)), " ", bin2hex(hebrev($s, 5)), "\n";
}
