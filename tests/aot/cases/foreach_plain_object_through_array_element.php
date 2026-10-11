<?php
class D { public $p = null; public $q = 'x'; }
/** @param array<int,array<int,D>> $items */
function f(array $items): void {
    foreach ($items as $providers) {
        foreach ($providers[0] as $k => $v) {
            echo $k, '=', var_export($v, true), "\n";
        }
    }
}
f([[new D]]);
