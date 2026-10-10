<?php
// foreach over a plain object (its public properties) through an array element segfaults
// issue: #164
class D { public $p = null; public $q = 'x'; }
function f(array $items): void {
    foreach ($items as $providers) {
        foreach ($providers[0] as $k => $v) {
            echo $k, '=', var_export($v, true), "\n";
        }
    }
}
f([[new D]]);
