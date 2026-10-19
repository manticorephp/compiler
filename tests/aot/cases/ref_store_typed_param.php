<?php
// A reference a callee stores to a TYPED by-ref param is never a dangling address.
class H { public array $refs = []; function bi(int $k, int &$v): void { $this->refs[$k] = &$v; }
  function bs(int $k, string &$v): void { $this->refs[$k] = &$v; } }
function f(H $h): void { $a = 2; $h->bi(1, $a); $s = "x"; $h->bs(2, $s); echo $h->refs[1], $h->refs[2], "\n"; }
$h = new H; f($h);
str_repeat("garbage", 100);
var_dump($h->refs);
