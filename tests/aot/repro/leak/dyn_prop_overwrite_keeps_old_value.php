<?php
// Overwriting a dynamic property through a runtime name never releases the old value; releasing it needs a dynamic read to take its own count first.
// issue: #592
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
function t() { $o = new stdClass(); $k = 'a'; $o->$k = new H("first"); echo "set\n"; $o->$k = new H("second"); echo "over\n"; }
t();
echo "end\n";
