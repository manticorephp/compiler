<?php
// Erased receiver calling a method nobody declares: the cold Error text.
class Magic { public function __call($n, $a) { return "magic:$n"; } }
class Plain { public function known() { return 'k'; } }
function call($o, $n) {
    try { return $o->undefinedThing(); } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
}
function call2($o) {
    try { return $o->otherName(1); } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
}
foreach ([new Plain, new Magic, null, 5, 1.5, 'str', [1], new stdClass] as $v) {
    echo call($v, 'x'), ' | ', call2($v), "\n";
}
