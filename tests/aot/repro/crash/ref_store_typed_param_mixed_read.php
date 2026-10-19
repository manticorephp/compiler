<?php
// A typed by-ref param whose reference was stored, then read in a mixed context in the callee, decodes the raw slot as a cell and crashes.
// issue: #349
class T { public array $r = []; function k(int &$v) { $this->r[] = &$v; var_dump($v); } }
$t = new T; $y = -2533274790391808; $t->k($y); echo "ok\n";
