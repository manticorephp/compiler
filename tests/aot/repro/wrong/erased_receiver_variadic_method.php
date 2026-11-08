<?php
// A variadic method reached through an erased receiver gets its trailing arguments unpacked when another class declares the name non-variadically.
// issue: #583
class QB { public array $s = []; public function select(mixed ...$sel): static { $this->s[] = json_encode($sel); return $this; } }
class RD { public function select($db) { return 1; } }
function er(mixed $q) { return $q->select('e1', 'e2'); }
echo json_encode(er(new QB)->s), "\n";
