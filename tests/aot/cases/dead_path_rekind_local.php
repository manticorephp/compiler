<?php
// A local re-kinded on one path (often one that never runs) and read on another
// must hold ONE representation at every merge. Expected output: php.
//
// run(): the if/else shadow exempted any name used as an array key anywhere in
// the function (`$llens[$k]` on a dead branch), so `if ($k > $stored) $k = $stored;`
// merged a cell with a raw int as `unknown` and `$olen + $k` added the boxed word.
// contShadow/breakShadow: the box-back planted after a trailing continue/break was
// dead code, so the jump carried the raw word to a slot read as a cell.
// nestedBoth..whileBreak, doContinue, switchContinue2, foreachBreak2: a break / continue /
// goto edge (any depth, any level) is joined at its target, not only an arm-final jump.
// strArr..switchIntStr, dumpPairs: two kinds with no common raw word (string/array,
// int/array, object/int, array/object, string/object, float/array, bool/string) ride a cell.
function rd(array &$st): int
{
    if ($st[1] >= \strlen($st[0])) { return -1; }
    $b = \ord($st[0][$st[1]]);
    $st[1] = $st[1] + 1;
    return $b;
}

function run(string $in): string
{
    $st = [$in, 0, 0, 0];
    $n = \strlen($in);
    $out = \str_repeat("\0", 16);
    $olen = 0;
    $stored = 0;
    $phase = 1;
    while (true) {
        if ($phase === 1) {
            $type = rd($st);
            if ($type < 0) { break; }
            if ($type === 48) {
                $phase = 2;
            } elseif ($type === 49) {
                $phase = 3;
            } elseif ($type === 50) {
                $lens = [];
                for ($k = 0; $k < 3; $k++) { $llens[$k] = $lens[$k]; }
            }
        }
        if ($phase === 2) {
            $k = $n - $st[1];
            if ($k > $stored) { $k = $stored; }
            $olen = $olen + $k;
            $kOut = $olen;
        } elseif ($phase === 3) {
            while (true) {
                $sym = rd($st);
                if ($sym === 46) { break; }
                $out[$olen] = \chr($sym);
                $olen = $olen + 1;
                for ($k = 0; $k < 1; $k++) {
                }
            }
        }
        $phase = 1;
    }
    return '[' . \substr($out, 0, $kOut) . ']';
}

/** @param array<int,mixed> $m */
function keyIfNoElse(array $m, int $dead): string
{
    $arr = [10, 20, 30, 40, 50, 60];
    if ($dead === 1) { for ($k = 0; $k < 3; $k++) { $arr[$k] = $k; } }
    $t = 7;
    $k = 5 - $m[1];
    if ($k > 2) { $k = 2; }
    $t = $t + $k;
    return 'a' . $t . ',' . ($k + 1) . ',' . $arr[$k] . ',' . \substr('abcdefghijkl', 0, $t);
}
/** @param array<int,mixed> $m */
function keyIfElse(array $m, bool $c): string
{
    $arr = [10, 20, 30, 40, 50, 60];
    $t = 7;
    if ($c) { $k = $m[1]; } else { $k = 3; }
    $t = $t + $k;
    return 'g' . $t . ',' . $arr[$k] . ',' . \substr('abcdefghijkl', 0, $t);
}
/** @param array<int,mixed> $m */
function deadSwitch(array $m, int $dead): string
{
    $v = [1, 2, 3, 4];
    $t = 1;
    switch ($dead) {
        case 1: $k = 'z'; break;
        case 2: $k = $m[1]; break;
        default: $k = 1;
    }
    $t = $t + $k;
    return 'f' . $t . ',' . $v[$k] . ',' . \substr('abcdefghijkl', 0, $t);
}
/** @param array<int,mixed> $m */
function contShadow(array $m): string
{
    $r = '';
    $kb = $m[1];
    $ph = 0;
    for ($i = 0; $i < 3; $i++) {
        if ($ph === 0) { $kb = 5; $ph = 1; continue; }
        $r .= ($kb + 1) . ',';
    }
    return $r;
}
/** @param array<int,mixed> $m */
function breakShadow(array $m): string
{
    $kb = $m[1];
    while (true) {
        if ($m[2] === 0) { $kb = 7; break; }
        $kb = $m[1];
    }
    return 'b' . ($kb + 1);
}
/** @param array<int,mixed> $m */
function elseContinue(array $m): string
{
    $r = '';
    $kb = 3;
    for ($i = 0; $i < 3; $i++) {
        if ($i > 0) { $kb = $m[1]; } else { $kb = 9; continue; }
        $r .= ($kb + 1) . ',';
    }
    return $r . '|' . $kb;
}
/** @param array<string,int> $h @param array<int,mixed> $m */
function deadForeachKey(array $h, array $m, int $dead): string
{
    $v = [1, 2, 3, 4, 5, 6];
    $k = $m[1];
    if ($dead === 1) { foreach ($h as $k => $x) { $v[0] = $x; } }
    $t = 1 + $k;
    return 'a' . $t . ',' . $v[$k] . ',' . \substr('abcdefgh', 0, $t);
}
/** @param array<int,mixed> $m */
function deadStringArith(array $m, int $dead): string
{
    $v = [1, 2, 3, 4, 5, 6];
    $n = 2;
    if ($dead === 1) { $n = 'x'; } elseif ($dead === 2) { $n = $m[1]; }
    $t = 1 + $n;
    return 'b' . $t . ',' . $v[$n] . ',' . \substr('abcdefgh', 0, $t);
}
/** @param array<int,mixed> $m */
function chain(array $m, int $a): string
{
    $x = 1;
    if ($a === 1) { $x = 's'; }
    if ($a === 2) { $x = 2.5; }
    if ($a === 3) { $x = $m[1]; }
    if ($a === 4) { $x = true; }
    return 'c' . \var_export($x, true);
}
/** @param array<int,mixed> $m */
function loopIfKey(array $m): string
{
    $v = [10, 20, 30, 40, 50];
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        if ($i % 2 === 0) { $k = $i; } else { $k = $m[1]; }
        $s = $s + $v[$k] + $k;
    }
    return 'd' . $s . ',' . \substr('abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz', 0, $s);
}
/** @param array<int,mixed> $m */
function deadMatch(array $m, int $dead): string
{
    $v = [1, 2, 3, 4];
    $k = match ($dead) { 1 => 'q', 2 => $m[1], default => 1 };
    $t = 1 + $k;
    return 'e' . $t . ',' . $v[$k];
}
/** @param array<int,mixed> $m */
function deadTry(array $m, int $dead): string
{
    $v = [1, 2, 3, 4];
    $k = 1;
    try {
        if ($dead === 1) { throw new \Exception('x'); }
        $k = $m[1];
    } catch (\Exception $e) {
        for ($k = 0; $k < 2; $k++) { $v[$k] = 0; }
    }
    $t = 1 + $k;
    return 'f' . $t . ',' . $v[$k];
}
/** @param array<int,mixed> $m */
function nestedContinue(array $m): string
{
    $r = '';
    $kb = $m[1];
    for ($i = 0; $i < 3; $i++) {
        if ($i === 0) {
            if ($m[2] === 0) { $kb = 5; continue; }
        }
        $r .= ($kb + 1) . ',';
    }
    return $r;
}
/** @param array<int,mixed> $m */
function twoNames(array $m, bool $c): string
{
    $a = 1;
    $b = 2;
    if ($c) { $a = $m[1]; $b = $m[1]; }
    return 'g' . ($a + 1) . ',' . ($b + 1);
}
/** @param array<int,mixed> $m */
function twoNamesLoop(array $m): string
{
    $r = '';
    for ($i = 0; $i < 3; $i++) {
        $a = $i;
        $b = $i + 10;
        if ($i === 1) { $a = $m[1]; $b = $m[1]; }
        $r .= ($a + 1) . ':' . ($b + 1) . ',';
    }
    return 'h' . $r;
}
/** @param array<int,mixed> $m */
function keyIfNoElseSmall(array $m, int $dead): string
{
    $arr = [10, 20, 30];
    if ($dead === 1) { for ($k = 0; $k < 3; $k++) { $arr[$k] = $k; } }
    $k = 5 - $m[1];
    if ($k > 2) { $k = 2; }
    return 'a' . ($k + 1) . ',' . $arr[$k];
}
/** @param array<int,mixed> $m */
function secondMergeAgree(array $m, bool $c): string
{
    if ($c) { $x = 1; } else { $x = 'a'; }
    $r = (string)$x;
    $x = 5;
    if (!$c) { $x = 6; }
    return 'b' . $r . ',' . ($x + 1);
}
function secondMergeDisagree(bool $c, bool $d): string
{
    $x = 1;
    if ($c) { $x = 'a'; }
    $r = (string)$x;
    $x = 3;
    if ($d) { $x = 'b'; }
    return 'c' . $r . ',' . $x;
}
/** @param array<string,int> $h */
function deadForeachKeyInt(array $h, int $dead): string
{
    $v = [1, 2, 3, 4];
    $s = 0;
    if ($dead === 1) { foreach ($h as $k => $x) { $s = $s + $x; } }
    $k = 1;
    $k = $k + 1;
    return 'd' . $v[$k] . ',' . ($k * 2) . ',' . $s;
}
/** @param array<int,mixed> $m */
function deadIfString(array $m, int $dead): string
{
    $v = [1, 2, 3, 4];
    $n = $m[1];
    if ($dead === 1) { $n = 'x'; }
    return 'e' . ($n + 1) . ',' . $v[$n];
}
/** @param array<int,mixed> $m */
function deadSwitchKey(array $m, int $dead): string
{
    $v = [1, 2, 3, 4];
    $k = 0;
    switch ($dead) {
        case 1: $k = 'z'; break;
        case 2: $k = $m[1]; break;
        default: $k = 1;
    }
    if ($k > 5) { $k = 0; }
    return 'f' . ($k + 1) . ',' . $v[$k];
}
/** @param array<int,mixed> $m */
function nestedBoth(array $m): string
{
    $r = '';
    $kb = $m[1];
    for ($i = 0; $i < 3; $i++) {
        if ($i === 0) { $kb = 5; if ($m[2] === 0) { continue; } else { continue; } }
        $r .= ($kb + 1) . ',';
    }
    return 'nb:' . $r;
}
/** @param array<int,mixed> $m */
function gotoDone(array $m): string
{
    if ($m[2] === 0) { $kb = 7; goto done; }
    $kb = $m[1];
    done:
    return 'gt:' . ($kb + 1);
}
/** @param array<int,mixed> $m */
function gotoBack(array $m): string
{
    $kb = $m[1];
    $n = 0;
    again:
    $r = $kb + 1;
    $n++;
    if ($n < 3) { $kb = 10 + $n; goto again; }
    return 'gb:' . $r;
}
/** @param array<int,mixed> $m */
function break2(array $m): string
{
    $kb = $m[1];
    for ($i = 0; $i < 3; $i++) {
        for ($j = 0; $j < 3; $j++) {
            if ($j === 1) { $kb = 9; break 2; }
        }
        $kb = $m[1];
    }
    return 'b2:' . ($kb + 1);
}
/** @param array<int,mixed> $m */
function continue2(array $m): string
{
    $r = '';
    $kb = $m[1];
    for ($i = 0; $i < 3; $i++) {
        $r .= ($kb + 1) . ',';
        foreach ([1, 2] as $j) {
            if ($j === 2) { $kb = 20 + $i; continue 2; }
        }
        $kb = $m[1];
    }
    return 'c2:' . $r;
}
/** @param array<int,mixed> $m */
function killedContinue(array $m): string
{
    $r = '';
    $kb = $m[1];
    for ($i = 0; $i < 3; $i++) {
        $r .= ($kb + 1) . ',';
        if ($i === 0) { $kb = 5; continue; }
        $kb = $m[1];
    }
    return 'kc:' . $r;
}
/** @param array<int,mixed> $m */
function switchInnerBreak(array $m, int $s): string
{
    $kb = $m[1];
    switch ($s) {
        case 1:
            if ($m[2] === 0) { $kb = 3; break; }
            $kb = $m[1];
            break;
        default:
            $kb = $m[1];
    }
    return 'sw:' . ($kb + 1);
}
/** @param array<int,mixed> $m */
function whileBreak(array $m): string
{
    $kb = $m[1];
    while (true) {
        if ($m[2] === 0) { $kb = 'x'; break; }
        $kb = $m[1];
    }
    return 'wb:' . $kb;
}
final class P { public int $v = 3; public function hi(): string { return 'P' . $this->v; } }
function show(string $tag, mixed $x): void
{
    echo $tag, ' ', \gettype($x), ' ', \var_export($x, true), ' ', \json_encode($x), "\n";
}
function strArr(bool $c): void
{
    if ($c) { $x = 'abc'; } else { $x = [1, 2]; }
    echo 'sa ', \gettype($x), ' ', \var_export($x, true), ' ', \json_encode($x), ' ';
    echo $c ? \strlen($x) : \count($x), "\n";
}
function intArr(bool $c): void
{
    $x = 5;
    if (!$c) { $x = [7, 8, 9]; }
    echo 'ia ', \gettype($x), ' ', \var_export($x, true), ' ', \json_encode($x), ' ';
    echo $c ? $x + 1 : \count($x), "\n";
}
function objInt(bool $c): void
{
    if ($c) { $x = new P(); } else { $x = 4; }
    echo 'oi ', \gettype($x), ' ', \json_encode($x), ' ';
    echo $c ? $x->hi() : $x * 2, "\n";
}
function arrObj(bool $c): void
{
    $x = [1];
    if ($c) { $x = new P(); }
    echo 'ao ', \gettype($x), ' ', \json_encode($x), ' ';
    echo $c ? $x->v : \count($x), "\n";
}
function strObj(bool $c): void
{
    $x = 'q';
    if ($c) { $x = new P(); }
    echo 'so ', \gettype($x), ' ', \json_encode($x), ' ';
    echo $c ? $x->hi() : \strtoupper($x), "\n";
}
function floatArr(bool $c): void
{
    $x = 1.5;
    if ($c) { $x = ['k' => 'v']; }
    echo 'fa ', \gettype($x), ' ', \var_export($x, true), ' ', \json_encode($x), ' ';
    echo $c ? $x['k'] : $x * 2, "\n";
}
function boolStr(bool $c): void
{
    $x = true;
    if ($c) { $x = 'yes'; }
    echo 'bs ', \gettype($x), ' ', \var_export($x, true), ' ', \json_encode($x), ' ';
    echo $c ? \strlen($x) : ($x ? 'T' : 'F'), "\n";
}
function loopStrArr(): void
{
    $x = 'a';
    for ($i = 0; $i < 3; $i++) {
        echo 'ls', $i, ' ', \gettype($x), ' ', \json_encode($x), "\n";
        $x = [$i];
    }
    echo 'ls ', \var_export($x, true), ' ', \count($x), "\n";
}
function switchIntStr(int $s): void
{
    $x = 1;
    switch ($s) { case 1: $x = 'one'; break; case 2: $x = [2]; break; }
    echo 'sw ', \gettype($x), ' ', \json_encode($x), "\n";
}
/** @param array<int,mixed> $m */
function doContinue(array $m): string
{
    $r = '';
    $kb = $m[1];
    $i = 0;
    do {
        $i++;
        $r .= ($kb + 1) . ',';
        if ($i === 1) { $kb = 30; continue; }
        $kb = $m[1];
    } while ($i < 3);
    return 'dc:' . $r;
}
/** @param array<int,mixed> $m */
function switchContinue2(array $m): string
{
    $r = '';
    $kb = $m[1];
    foreach ([0, 1, 2] as $i) {
        $r .= ($kb + 1) . ',';
        switch ($i) {
            case 0: $kb = 40; continue 2;
            default: break;
        }
        $kb = $m[1];
    }
    return 'sc:' . $r;
}
/** @param array<int,mixed> $m */
function foreachBreak2(array $m): string
{
    $kb = $m[1];
    foreach ([1, 2] as $a) {
        foreach ([3, 4] as $b) {
            if ($b === 4) { $kb = 'z' . $a; break 2; }
        }
        $kb = $m[1];
    }
    return 'fb:' . $kb;
}
function dumpPairs(bool $c): void
{
    if ($c) { $x = 'abc'; } else { $x = [1, 2]; }
    \var_dump($x);
    $y = 5;
    if ($c) { $y = new P(); }
    \var_dump($y);
}

echo run('1Hello.0'), "\n";
$m = ['s', 4, 0, 0];
echo keyIfNoElse($m, 0), "\n";
echo keyIfNoElse(['s', 1, 0, 0], 0), "\n";
echo keyIfElse($m, true), "\n";
echo keyIfElse($m, false), "\n";
echo deadSwitch(['s', 2], 2), "\n";
echo deadSwitch($m, 3), "\n";
$m = ['s', 2, 0];
echo contShadow($m), "\n", breakShadow($m), "\n", elseContinue($m), "\n";
foreach ([0, 1, 2, 3, 4] as $a) { echo chain($m, $a), "\n"; }
echo deadForeachKey(['a' => 1], $m, 0), "\n";
echo deadStringArith($m, 0), "\n", deadStringArith($m, 2), "\n";
echo loopIfKey($m), "\n";
echo deadMatch($m, 2), "\n", deadMatch($m, 0), "\n";
echo deadTry($m, 0), "\n";
echo nestedContinue($m), "\n";
$m = ['s', 5];
echo twoNames($m, false), "\n", twoNames($m, true), "\n", twoNamesLoop($m), "\n";
$m = ['s', 2, 0, 0];
echo keyIfNoElseSmall($m, 0), "\n";
echo secondMergeAgree($m, true), "\n", secondMergeAgree($m, false), "\n";
echo secondMergeDisagree(true, false), "\n", secondMergeDisagree(false, true), "\n";
echo deadForeachKeyInt(['a' => 1], 0), "\n";
echo deadIfString($m, 0), "\n";
echo deadSwitchKey($m, 2), "\n", deadSwitchKey($m, 3), "\n";

$m = ['s', 2, 0];
echo nestedBoth($m), "\n", gotoDone($m), "\n", gotoBack($m), "\n", break2($m), "\n", continue2($m), "\n";
echo killedContinue($m), "\n", switchInnerBreak($m, 1), "\n", whileBreak($m), "\n";

foreach ([true, false] as $c) { strArr($c); intArr($c); objInt($c); arrObj($c); strObj($c); floatArr($c); boolStr($c); }
loopStrArr();
switchIntStr(0); switchIntStr(1); switchIntStr(2);

$m = ['s', 2, 0];
echo doContinue($m), "\n", switchContinue2($m), "\n", foreachBreak2($m), "\n";
dumpPairs(true);
dumpPairs(false);
