<?php
// Assigning a non-array to an `array` property is a TypeError; the store is accepted silently.
class O { public array $p = []; }
$o = new O; $v = 5;
try { $o->p = $v; echo "no error\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
function m(): mixed { return 5; }
try { $o->p = m(); echo "no error\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
