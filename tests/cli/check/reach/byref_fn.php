<?php
function fa(): void { echo "a\n"; }
function unrelated(): void { echo "u\n"; }
function byRef(bool $f): void {
    if ($f) { $fn = 'fa'; } else { $fn = 'fa'; }
    $swap = function () use (&$fn) { $fn = strrev('af'); };
    $swap();
    $fn();
}
byRef(true);
