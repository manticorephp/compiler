<?php
// `[] + $b` where the empty left side still carries another element hint.
class O { public function __construct(public string $n) {} }
function mk(): array { $a = ['x' => 'str']; unset($a['x']); return $a; }
function mk2(): array { $a = ['x' => 1.5]; unset($a['x']); return $a; }
function un(array $a, array $b): string {
    $r = $a + $b; $o = '';
    foreach ($r as $k => $v) { $o .= $k . '=' . (is_object($v) ? $v->n : (string)$v) . ' '; }
    return $o;
}
$b = ['k' => new O('obj'), 'j' => new O('o2')];
echo un(mk(), $b), "\n";
echo un(mk2(), $b), "\n";
