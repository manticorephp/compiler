<?php
interface R { public function find(): int; }
class A implements R { public function find(): int { return 1; } }
class B implements R { public function find(): int { return 2; } }
function look(R $r): int { return $r->find(); }
echo look(new A()), "\n";
