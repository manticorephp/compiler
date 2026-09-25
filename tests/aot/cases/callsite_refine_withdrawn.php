<?php
// A call-site refinement made while one site was still erased is withdrawn
// when that site types to a different element (symfony Finder's
// VcsIgnoredFilterIterator: array_reverse over list<string> and list<array{…}>).
/** @return list<array{0: string, 1: bool, 2: bool}> */
function rules(): array { $r = []; $r[] = ['/a/', false, true]; $r[] = ['/b/', true, false]; return array_reverse($r); }
/** @return list<string> */
function dirs(string $p): array { $o = []; while (true) { $n = \dirname($p); if ($n === $p) { break; } $o[] = $p = $n; } return $o; }
foreach (array_reverse(dirs('/x/y/z')) as $d) { echo $d, "\n"; }
foreach (rules() as [$re, $neg, $dir]) { echo $re, (int)$neg, (int)$dir, "\n"; }
