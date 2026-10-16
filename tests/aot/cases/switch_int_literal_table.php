<?php
// Int subject over int literals lowers to one LLVM switch: duplicates, a
// default in the middle, fall-through and negative labels keep php's semantics.
function f(int $n): string {
    $r = '';
    switch ($n) {
        case 1: $r .= 'one';
        case 2: $r .= 'two'; break;
        case 3:
        case 4: $r .= 'three-four'; break;
        default: $r .= 'dflt';
        case -5: $r .= 'neg'; break;
        case 1: $r .= 'dup'; break;
        case 9223372036854775807: $r .= 'max'; break;
    }
    return $r;
}
foreach ([1, 2, 3, 4, 5, -5, 0, 9223372036854775807] as $n) { echo $n, ' ', f($n), "\n"; }
