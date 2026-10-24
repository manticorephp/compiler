<?php
// A reference stored to a typed by-ref param (int &$v) holds a copy, so later writes on either side are not shared.
class H { public array $refs = []; function bi(int $k, int &$v): void { $this->refs[$k] = &$v; } }
function f(H $h): void { $a = 2; $h->bi(1, $a); $a = 3; echo $h->refs[1], "\n"; $h->refs[1] = 4; echo $a, "\n"; }
f(new H);
