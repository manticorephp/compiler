<?php
// A parameter reassigned to explode() and to [$obj, '__invoke'] must compile (emit recursed without bound)
function refl($cb) {
    if (is_string($cb)) {
        $cb = explode('::', $cb);
    } elseif (is_object($cb)) {
        $cb = [$cb, '__invoke'];
    }
    return is_array($cb) ? 1 : 2;
}
echo refl('strlen'), "\n";
