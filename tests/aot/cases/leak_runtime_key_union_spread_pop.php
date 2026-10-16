<?php
// Runtime-string keys through `+`, `...` and a popped array used as an argument must not leak.
function mk(string $s): array { $o = []; foreach ([$s . "1", $s . "2"] as $n) { $o[$n] = $n; } return $o; }
function union(): int { $p = []; $p += mk("a"); return count($p); }
function spread(): int { $r = [...mk("a"), ...mk("b")]; return count($r); }
function popArg(): int { $l = [mk("a"), mk("b")]; $n = 0; while ($l) { $n += count(array_pop($l)); } return $n; }
function shiftArg(): int { $l = [mk("a"), mk("b")]; return count(array_shift($l)) + count(array_shift($l)); }
function growth(callable $f): string {
    for ($i = 0; $i < 2000; $i++) { $f(); }
    $b = memory_get_usage();
    for ($i = 0; $i < 20000; $i++) { $f(); }
    return (memory_get_usage() - $b) < 1000000 ? 'flat' : 'leak';
}
echo "union ", union(), " ", growth('union'), "\n";
echo "spread ", spread(), " ", growth('spread'), "\n";
echo "pop ", popArg(), " ", growth('popArg'), "\n";
echo "shift ", shiftArg(), " ", growth('shiftArg'), "\n";
