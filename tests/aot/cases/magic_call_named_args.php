<?php
// Named arguments passed to an undefined method are not collected into __call's $arguments under their names.
class M { public function __call($n, $a) { return $n . ':' . json_encode($a); } }
echo (new M)->go('x', b: 'or', c: 3), "\n";
