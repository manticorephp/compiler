<?php
class T { public array $r = []; }
function viaProp($i) {
    $t = new T; $y = "z$i"; $t->r[] = &$y;
    $t->r[0] = "q$i"; $t->r[0] = "r" . $i; $t->r[0] = $y . "!";
    return $y . "|" . $t->r[0];
}
function viaLocal($i) {
    $a = []; $y = "z$i"; $a[] = &$y; $a['k'] = &$y;
    $a[0] = "first$i"; $a['k'] = "key$i"; $a[0] = $a['k'];
    return $y . "|" . count($a);
}
function viaObject() {
    $o = new stdClass; $y = new stdClass; $y->n = 1; $a = [&$y];
    $a[0] = $o; $a[0]->n = 5;
    return $y->n . (int)($y === $o);
}
function viaArray() {
    $y = [1, 2]; $a = [&$y]; $a[0] = [3, 4, 5]; $a[0] = $y;
    return implode(",", $y) . "/" . implode(",", $a[0]);
}
function selfAssign($i) { $y = "s$i"; $a = [&$y]; $a[0] = $a[0]; $a[0] = $y; return $y; }
for ($i = 0; $i < 3; $i++) {
    echo viaProp($i), " ", viaLocal($i), " ", viaObject(), " ", viaArray(), " ", selfAssign($i), "\n";
}
