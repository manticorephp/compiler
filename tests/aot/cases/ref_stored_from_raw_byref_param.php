<?php
// A reference stored from a raw-typed by-ref param (`int &$p`, `$out[0] = &$p`) is a copy; writes through it never reach the caller.
/** @return array<int, mixed> */
function keep(int &$p): array { $out = []; $out[0] = &$p; return $out; }
$q = 1; $o = keep($q); $o[0] = 9; var_dump($q);
