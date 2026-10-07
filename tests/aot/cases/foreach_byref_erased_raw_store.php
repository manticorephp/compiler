<?php
function ob(mixed $m){ foreach($m as &$v){ $v=[1,2]; foreach($v as $k=>&$w){ unset($v[$k]); } } return $m; }
var_dump(ob([1,2]));
function a1(mixed $m){ foreach($m as &$v){ $v=[1,2]; } return $m; }
var_dump(a1([1,2]));
function a2(mixed $m){ foreach($m as &$v){ $v="str"; } return $m; }
var_dump(a2([1,2]));
function a3(mixed $m){ foreach($m as &$v){ $v=1.5; } return $m; }
var_dump(a3([1,2]));
function a4(mixed $m){ foreach($m as &$v){ $v=new stdClass; } return $m; }
echo get_class(a4([1,2])[1]), "\n";
function a5(mixed $m){ foreach($m as &$v){ $v=['a'=>1]; } return $m; }
var_dump(a5(['x'=>1,'y'=>2]));
class P { public $p; public static $s; }
function b1(){ $o=new P; $o->p=['q','r']; foreach($o->p as &$v){ $v=[1,2]; $v[]=3; } return $o->p; }
var_dump(b1());
function b2(){ P::$s=['q','r']; foreach(P::$s as &$v){ $v="str".$v; } return P::$s; }
var_dump(b2());
function b3(){ P::$s=['q','r']; foreach(P::$s as &$v){ $v=2.5; } return P::$s; }
var_dump(b3());
function b4(){ $o=new P; $o->p=['q','r']; foreach($o->p as &$v){ $v=[7]; } return $o->p; }
var_dump(b4());
