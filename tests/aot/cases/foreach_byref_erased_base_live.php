<?php
// A by-ref foreach over an ERASED base (an element of an untyped array
// property / a mixed local) must walk the LIVE array: the body's unset()
// relocates the buffer, and the loop used to keep reading and writing the
// freed one (php-cs-fixer's EventDispatcher::removeListener).
final class D {
    public array $ls = [];
    public function rm(string $e, string $x): void {
        foreach ($this->ls[$e] as $p => &$list) {
            foreach ($list as $k => &$v) { if ($v === $x) { unset($list[$k]); } }
            if (!$list) { unset($this->ls[$e][$p]); }
        }
    }
    public function rmInner(string $e, string $x): void {
        foreach ($this->ls[$e] as $p => &$list) {
            foreach ($list as $k => &$v) {
                if ($v === $x) { unset($list[$k]); }
                $junk = [];
                for ($j = 0; $j < 8; $j++) { $junk[] = str_repeat('j', 24); }
                $v = $v . '';
            }
        }
    }
}
function rmLocal(array &$ls, string $e, string $x): void {
    foreach ($ls[$e] as $p => &$list) {
        foreach ($list as $k => &$v) { if ($v === $x) { unset($list[$k]); } }
        if (!$list) { unset($ls[$e][$p]); }
    }
}
for ($round = 0; $round < 200; $round++) {
    $d = new D();
    $d->ls['e'][0][] = 'a'; $d->ls['e'][0][] = 'b'; $d->ls['e'][5][] = 'a';
    $d->ls['e'][7] = ['c', 'a', 'd', 'a'];
    $d->rm('e', 'a');
    $d->ls['f'][1] = ['a', 'x', 'a', 'y', 'z'];
    $d->rmInner('f', 'a');
    $ls = [];
    $ls['e'][0][] = 'a'; $ls['e'][0][] = 'b'; $ls['e'][5][] = 'a';
    rmLocal($ls, 'e', 'a');
    if ($round === 0) { var_dump($d->ls, $ls); }
    $m = ['q' => ['a', 'b', 'a']];
    foreach ($m['q'] as $k => &$v) { if ($v === 'a') { unset($m['q'][$k]); } else { $v = strtoupper($v); } }
    unset($v);
    if ($round === 0) { var_dump($m); }
}
echo "done\n";
