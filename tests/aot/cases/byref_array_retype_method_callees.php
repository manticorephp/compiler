<?php
// A by-reference array argument that a static method, an instance method or a constructor replaces (or appends to) with
// elements of another kind reads back as those elements in the caller, directly and through a function or a method that
// hands it on (#485: only a plain function call widened the caller's element type).
final class L { public int $n = 1; }
final class C
{
    public static function put(array &$out): void { $o = []; $o[] = new L(); $out = $o; }
    public function add(array &$out): void { $out[] = new L(); }
    public function replace(array &$out): void { $o = []; $o[] = new L(); $out = $o; }
    public function relay(array &$out): void { $this->replace($out); }
    public function __construct(?array &$out = null) { if ($out !== null) { $out = [1.5]; } }
}
function hop(array &$out): void { C::put($out); }
function viaStatic(): void { $out = ['stale']; C::put($out); var_dump(gettype($out[0]), $out[0] instanceof L); }
function viaHop(): void { $out = ['stale', 'x']; hop($out); var_dump(count($out), $out[0] instanceof L); }
function viaMethod(): void { $out = ['s']; (new C())->add($out); var_dump(gettype($out[0]), gettype($out[1]), $out[1]->n); }
function viaMethodReplace(): void { $out = ['s']; (new C())->replace($out); var_dump(gettype($out[0]), $out[0] instanceof L); }
function viaMethodHop(): void { $out = ['s', 't']; (new C())->relay($out); var_dump(count($out), gettype($out[0]), $out[0] instanceof L); }
function viaCtor(): void { $out = ['s']; new C($out); var_dump($out); }
viaStatic();
viaHop();
viaMethod();
viaMethodReplace();
viaMethodHop();
viaCtor();
