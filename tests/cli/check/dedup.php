<?php
/** @param int[] $xs */
function d(array $xs) {
    foreach ($xs as $x) {
        $r = array_map(fn($v) => $GLOBALS, [$x]);
    }
}
