<?php
// Appending a reference through $GLOBALS['h']->r[] into a raw-element array bypasses the compile-time reject and corrupts memory.
// issue: #350
class H { public array $r = []; }
$h = new H; $x = "s";
$GLOBALS['h']->r[] = &$x; $h->r[0] = 99; $h->r[] = "t"; echo $x, "\n"; unset($h);
