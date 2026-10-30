<?php
// A function whose only return is an int literal, when it falls off the end, yields int(0) where php yields NULL.
function h($x) { if ($x) { return 1; } }
var_dump(h(true));
var_dump(h(false));
