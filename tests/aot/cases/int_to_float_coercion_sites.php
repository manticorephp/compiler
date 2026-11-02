<?php
class P {
    public float $f = 0.0;
    public static float $s = 0.0;
    public function __construct(public float $c = 1) {}
    function r(int $a): float { return $a; }
    static function sr(int $a): float { return $a * 2; }
}
function p(int $a): float { return $a; }
function m(int $a, int $b): float { return $a * $b; }
function lit(): float { return 7; }
function tern(int $a, bool $c): float { return $c ? $a : 2; }
function fromArr(array $a): float { return $a[0]; }
function t($l, $x) { echo $l, ': '; var_dump($x); }
$i = 5;
t('p', p($i)); t('m', m(2, 3)); t('lit', lit());
t('tern', tern($i, true)); t('tern2', tern($i, false));
t('arr', fromArr([4]));
$o = new P($i);
t('promoted', $o->c);
$o->f = $i; t('prop', $o->f);
P::$s = $i; t('static', P::$s);
t('meth', $o->r($i)); t('smeth', P::sr($i));
$cl = function (int $a): float { return $a; }; t('closure', $cl($i));
$ar = fn(int $a): float => $a; t('arrow', $ar($i));
