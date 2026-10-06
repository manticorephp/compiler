<?php
// finally runs on every exit from a try: return (value computed first), break N /
// continue N across nested loops and switch, goto out, and from a catch.
function retTry(): string { $s = 'v' . str_repeat('a', 3); try { return $s; } finally { echo "fin1 ", $s, "\n"; $s = 'changed'; } }
echo retTry(), "\n";
function retCatch(): int { try { throw new RuntimeException('x'); } catch (RuntimeException $e) { return 2; } finally { echo "fin2\n"; } }
echo retCatch(), "\n";
function override(): int { try { return 1; } finally { return 3; } }
echo override(), "\n";
function nested(): int {
    try { try { return 10; } finally { echo "inner\n"; } } finally { echo "outer\n"; }
}
echo nested(), "\n";
for ($i = 0; $i < 3; $i++) {
    for ($j = 0; $j < 3; $j++) {
        try {
            try { if ($j === 1) { break 2; } echo "body $i $j\n"; } finally { echo "f-in $i $j\n"; }
        } finally { echo "f-out $i $j\n"; }
    }
}
echo "after break 2\n";
foreach ([1, 2, 3] as $v) {
    try { if ($v === 2) { continue; } echo "v$v\n"; } finally { echo "fin v$v\n"; }
}
$k = 0;
while (true) {
    switch ($k) {
        case 0:
            try { $k++; continue 2; } finally { echo "sw-cont\n"; }
        default:
            try { break 2; } finally { echo "sw-break\n"; }
    }
}
echo "k=$k\n";
function gotoOut(): void {
    try { echo "in\n"; goto out; } finally { echo "goto-fin\n"; }
    echo "skipped\n";
    out:
    echo "landed\n";
}
gotoOut();
function cont(): int {
    $n = 0;
    for ($i = 0; $i < 4; $i++) {
        try { if ($i % 2) { continue; } $n += 10; } finally { $n++; }
    }
    return $n;
}
echo cont(), "\n";
function gen() { try { yield 1; return 5; } finally { echo "gen-fin\n"; } }
$g = gen();
foreach ($g as $x) { echo "y$x\n"; }
echo $g->getReturn(), "\n";
try {
    foreach ([1, 2] as $v) {
        try { break; } finally { throw new LogicException("from-fin $v"); }
    }
} catch (LogicException $e) { echo "caught ", $e->getMessage(), "\n"; }
function finThrowOnReturn(): int {
    try { try { return 1; } finally { throw new LogicException('fr'); } } catch (LogicException $e) { echo "outer caught ", $e->getMessage(), "\n"; }
    return 7;
}
echo finThrowOnReturn(), "\n";
function noSelfCatch(): void {
    try {
        try { return; } catch (Exception $e) { echo "WRONG self-catch\n"; } finally { throw new RuntimeException('nsc'); }
    } catch (RuntimeException $e) { echo "ok ", $e->getMessage(), "\n"; }
}
noSelfCatch();
// Owned locals live into the finally a jump runs, and are dropped exactly once.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "drop ", $this->n, "\n"; } }
function owned(): void {
    for ($i = 0; $i < 3; $i++) {
        $t = new Tok("t$i");
        $s = str_repeat('s', 2 + $i);
        try {
            if ($i === 0) { continue; }
            if ($i === 2) { break; }
            $u = new Tok("u$i");
        } finally { echo "fin ", $t->n, ' ', $s, "\n"; }
    }
    echo "end owned\n";
}
owned();
function ownedRet(): Tok {
    $a = new Tok('a');
    $b = new Tok('b');
    try { return $a; } finally { echo "fin ", $b->n, "\n"; }
}
$r = ownedRet();
echo $r->n, "\n";
unset($r);
echo "done\n";
