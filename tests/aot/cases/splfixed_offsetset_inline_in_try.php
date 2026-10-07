<?php
final class Toks extends SplFixedArray {
    public function offsetSet($index, $newval): void {
        try {
            parent::offsetSet($index, $newval);
        } catch (\RuntimeException $e) {
            echo 'caught ', get_class($e), ' ', \count($e->getTrace()) > 0 ? 'trace' : 'none', "\n";
        }
    }
}
$t = new Toks(3);
$t[0] = 'a';
$t[2] = 'c';
$t[7] = 'x';
foreach ($t as $i => $v) { echo $i, '=', var_export($v, true), "\n"; }
