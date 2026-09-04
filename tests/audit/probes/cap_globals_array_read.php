<?php
// @epic: sapi-layer
// @why: LowerSuperglobals rejects a whole-array $GLOBALS read as a hard compile
//       error. This probe pins WHICH form fails: a literal key read works, the
//       array itself does not. Recorded so the SAPI epic knows the boundary.

$GLOBALS['capX'] = 41;
var_dump($GLOBALS['capX']);
var_dump(isset($GLOBALS['capX']));
var_dump(isset($GLOBALS['capNeverSet']));

$n = 0;
foreach ($GLOBALS as $k => $v) { $n++; }
var_dump($n > 0);
