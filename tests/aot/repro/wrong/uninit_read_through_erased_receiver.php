<?php
// Reading an unset typed property through an erased receiver answers null instead of throwing.
// issue: #594
class P { public array $t; }
function mk(): object { return new P; }
$o = mk();
try { echo json_encode($o->t), "\n"; } catch (Error $e) { echo $e->getMessage(), "\n"; }
