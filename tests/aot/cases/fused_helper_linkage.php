<?php
// Regression: a stdlib fn (gethostbynamel) uses in_array, so the stdlib emits the
// fused __mc_fuse_inarray helper. A USER program's own in_array / array_map must
// not collide with it — the helper is internal + never exported in the .sig.
// Before the fix this failed to COMPILE (invalid redefinition).
$a = ['x', 'y', 'z'];
var_dump(in_array('y', $a, true));
var_dump(in_array('q', $a, true));
var_dump(array_map(fn($n) => $n * 2, [1, 2, 3]));
var_dump(gethostbynamel('127.0.0.1'));
echo "ok\n";
