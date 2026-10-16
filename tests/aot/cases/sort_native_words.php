<?php
function lcg(int &$s): int { $s = ($s * 1103515245 + 12345) & 0x7fffffff; return $s; }
foreach ([0, 1, 2, 7, 8, 9, 17, 1000] as $n) {
    $seed = 42 + $n;
    $ints = []; $strs = []; $neg = []; $nums = [];
    for ($i = 0; $i < $n; $i++) {
        $v = lcg($seed) % 500;
        $ints[] = $v; $neg[] = $v - 250; $strs[] = "k" . $v . ($v % 3 === 0 ? "z" : ""); $nums[] = 'n' . $v . 'q';
    }
    $cells = json_decode(json_encode($strs), true);
    foreach (['ints' => $ints, 'neg' => $neg, 'strs' => $strs, 'nums' => $nums, 'cells' => $cells] as $name => $a) {
        $copy = $a;
        $s = $a; sort($s);
        $ok = $copy === $a ? 'source intact' : 'SOURCE CHANGED';
        echo $n, ' ', $name, ' ', md5(json_encode($s)), ' ', $ok, "\n";
    }
}
// case, empty strings, duplicates
$t = ["b", "a", "B", "", "b", "aa", "A"];
sort($t); echo json_encode($t), "\n";
$t = ["b", "a", "", "c", ""]; sort($t); echo json_encode($t), "\n";
// mixed and nullable shapes keep the generic path
$m = [3, "2", 1.5, null, "a", true]; sort($m); echo json_encode($m), "\n";
$f = [2.5, -1.5, 10.25, 0.0]; sort($f); echo json_encode($f), "\n";
$h = ['x' => 3, 'y' => 1, 'z' => 2]; sort($h); echo json_encode($h), "\n";
$u = [5, 3, 9, 1]; unset($u[1]); sort($u); echo json_encode($u), "\n";
// a shared buffer is separated before the in-place sort
$a = [5, 2, 8, 1]; $b = $a; sort($b); echo json_encode($a), json_encode($b), "\n";
$p = ["pear", "apple", "fig"]; $q = $p; sort($q); echo json_encode($p), json_encode($q), "\n";
function srt(array $x): array { sort($x); return $x; }
$orig = ["z", "y", "x"]; $r = srt($orig); echo json_encode($orig), json_encode($r), "\n";
class Box { public array $items = ["c", "a", "b"]; public function s(): array { sort($this->items); return $this->items; } }
$bx = new Box; echo json_encode($bx->s()), json_encode($bx->items), "\n";
$big = []; for ($i = 0; $i < 5000; $i++) { $big[] = "n" . (($i * 7919) % 5003); }
$g = $big; sort($g); echo md5(implode(',', $g)), ' ', $g[0], ' ', $g[4999], "\n";
