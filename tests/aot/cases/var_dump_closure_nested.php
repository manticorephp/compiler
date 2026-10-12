<?php
// A Closure held in an object property or an array dumps as object(Closure), not stdClass.
class Holder { public $c; public $arr; }
$f = function () { return 1; };
$h = new Holder;
$h->c = $f;
$h->arr = [$f];
var_dump($h);
var_dump([$f]);
