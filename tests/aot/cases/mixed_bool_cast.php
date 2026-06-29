<?php
// (bool)$cell — unbox by tag (a boxed 0/false/"" has non-zero raw bits).
function b(mixed $x): string { return ((bool)$x) ? "T" : "F"; }
echo b(0), b(1), b(""), b("0"), b("x"), b([]), b([1]), b(0.0), b(2.5), "\n";
