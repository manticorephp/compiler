<?php
function g1(array $cfg) {
    $c = $cfg['cls'];
    if (class_exists($c)) { new Missing\C(); }
}
function g2(string $n) {
    if (!function_exists($n)) { return; }
    missing_mf();
}
function g3(string $n) {
    return function_exists($n) && missing_mg();
}
function g4(string $c) {
    return class_exists($c) ? new Missing\D() : null;
}
function g5() {
    if (class_exists('Missing\Folded')) { new Missing\Folded(); }
    if (function_exists('missing_folded')) { missing_folded(); }
}
g1(['cls' => 'x']); g2('a'); g3('b'); g4('c'); g5();
