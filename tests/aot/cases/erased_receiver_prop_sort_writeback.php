<?php
// sort-family builtins on an array property read through an ERASED receiver (a
// `mixed` param) write the sorted buffer back to the object, as array_shift /
// array_pop do; before, the sort went into a throwaway word — or SIGSEGV'd once
// the same property had been shifted / appended / unshifted.
class H { public array $j = []; /** @var array<string,int> */ public array $m = []; }
final class K extends H {}
function srt(mixed $o): void { sort($o->j); }
function sh(mixed $o): void { array_shift($o->j); $o->j[] = 0; array_unshift($o->j, 9); sort($o->j); }
function rev(mixed $o): void { rsort($o->j); usort($o->j, fn($a, $b) => $b <=> $a); }
function keys(mixed $o): void { ksort($o->m); }
function vals(mixed $o): void { arsort($o->m); }
$o = new K(); $o->j = [3, 1, 2];
srt($o); var_dump($o->j);
sh($o); var_dump($o->j);
rev($o); var_dump($o->j);
$o->m = ['b' => 2, 'a' => 1, 'c' => 3];
keys($o); var_dump($o->m);
vals($o); var_dump($o->m);
