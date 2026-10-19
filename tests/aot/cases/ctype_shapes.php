<?php
$fns = ['ctype_alnum', 'ctype_alpha', 'ctype_cntrl', 'ctype_digit', 'ctype_graph', 'ctype_lower', 'ctype_print', 'ctype_punct', 'ctype_space', 'ctype_upper', 'ctype_xdigit'];
foreach ($fns as $fn) {
    $row = '';
    for ($b = 0; $b < 128; $b++) { $row .= $fn(chr($b)) ? '1' : '0'; }
    echo $fn, ' ', md5($row), ' ', $row === str_repeat('0', 128) ? 'none' : strlen(str_replace('0', '', $row)), "\n";
}
foreach (['', 'a', 'A', '0', 'abc', 'ABC', 'aBc', '123', '12a', 'a1', "a\0", "\n\t ", ' ', '!?', 'fF09', 'g', str_repeat('x', 100000), str_repeat('1', 99999) . 'a'] as $s) {
    $r = '';
    foreach ($fns as $fn) { $r .= $fn($s) ? '1' : '0'; }
    echo bin2hex(substr($s, 0, 4)), ' ', $r, "\n";
}
// ints: -128..255 are char codes, others are decimal strings; other types are false
foreach ([-129, 0, 9, 32, 48, 57, 65, 97, 127, 256, 1234, 99999] as $v) {
    $r = '';
    foreach ($fns as $fn) { $r .= @$fn($v) ? '1' : '0'; }
    echo $v, ' ', $r, "\n";
}
foreach ([null, true, false, 1.5, [], new stdClass] as $v) {
    $r = '';
    foreach ($fns as $fn) { $r .= @$fn($v) ? '1' : '0'; }
    echo gettype($v), ' ', $r, "\n";
}
