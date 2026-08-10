<?php
// A reference to a SUPERGLOBAL stored as a value — symfony/runtime's
// GenericRuntime.php:162 writes `['session' => &$_SESSION]`, and the tier-4
// build stopped on it: a superglobal is a GLOBAL-BACKED local, which has no
// alloca, so `byRefAddrOf` answered "no address" and the reference was refused.
// The module cell IS its storage, so the cell's address is the answer — the
// same shape a static property already had.
$_SESSION['a'] = 1;

$ctx = ['session' => &$_SESSION];
$ctx['session']['b'] = 2;
echo "through ref : ", count($_SESSION), " ", $_SESSION['b'], "\n";

$_SESSION['c'] = 3;
echo "back again  : ", count($ctx['session']), " ", $ctx['session']['c'], "\n";

// ⚠ NOT covered: `global $store; $store = ['x' => 1];` then
// `['ref' => &$store]`. Same storage class, but its elements are RAW while a
// write through a reference goes via the cell channel, so `$store['y']` reads
// back a denormal. That shape still gets the loud refusal — pinning it here
// would pin a wrong answer. It closes with the ref-taken-local-is-CELL rule
// applied to global storage (docs/design/reference-cells.md).

// two references to the same superglobal see each other
$one = ['s' => &$_SESSION];
$two = ['s' => &$_SESSION];
$one['s']['shared'] = 'yes';
echo "two refs    : ", $two['s']['shared'], "\n";
