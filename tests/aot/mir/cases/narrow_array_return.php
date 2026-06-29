<?php
// `: array` return narrows to vec[int] (NarrowReturns) so the caller
// tracks + releases the +1 owned vec — `mem_rc_release vec x` below.
function build(): array {
    $out = [];
    $out[] = 1;
    $out[] = 2;
    return $out;
}
$x = build();
echo $x[0];
