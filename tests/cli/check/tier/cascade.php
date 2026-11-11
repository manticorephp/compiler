<?php
function outer() {
    $o = new \stdClass();
    foreach ([1] as &$o->p) {}
    inner_missing();
}
outer();
