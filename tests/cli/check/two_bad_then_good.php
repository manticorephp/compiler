<?php
function a() { $g = $GLOBALS; return $g; }
function b() { $a = [1]; $o = new stdClass; foreach ($a as &$o->p) {} }
function c() {
    $s = 0;
    foreach ([1, 2, 3] as $i) {
        try { $s += $i; } catch (\Throwable $e) { $s = 0; }
    }
    return $s;
}
