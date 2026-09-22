<?php
// `(object)` of a value whose kind is only known at run time follows php's
// per-kind rules: an array becomes a stdClass whose properties are a COPY of
// it, null an empty stdClass, a scalar `{"scalar": v}`, an object itself.
// Before, every cell was read as the assoc pointer — `settype($v, 'object')`
// over `mixed &$v` inttoptr'd a tagged word and var_dump SIGSEGV'd.
function toObj(mixed $v): object { return (object)$v; }
function st(mixed &$v): void { $v = (object)$v; }
class P { public int $n = 7; }

$o = toObj(['x' => 1, 'y' => 'two']);
var_export($o); var_dump($o->x, $o->y);
var_export(toObj(null)); var_export(toObj(5)); var_export(toObj('s')); var_export(toObj(2.5)); var_export(toObj(true));
$p = new P(); $q = toObj($p); var_dump($q === $p, $q->n);
$a = ['k' => [1, 2]]; st($a); var_export($a); var_dump(is_object($a), $a->k[1]);
$n = 9; st($n); var_dump($n->scalar);
// The copy: writing the object leaves the source array alone, and vice versa.
$src = ['a' => 1]; $obj = toObj($src); $obj->a = 2; $obj->b = 3; $src['c'] = 4;
var_dump($src); var_export($obj);
// settype through the stdlib.
$s = ['m' => 'v']; var_dump(settype($s, 'object'), $s->m); $t = 1; settype($t, 'object'); var_export($t);
echo json_encode(toObj(['j' => [1, 'x']])), "\n";
$assoc = false; var_export(json_decode('{"d":1,"e":[2]}', $assoc));
// A statically-typed object is itself; a statically-typed array a fresh copy.
$p2 = new P(); $r = (object)$p2; var_dump($r === $p2); $r->n = 8; var_dump($p2->n);
$arr = ['q' => 1]; $ob = (object)$arr; $ob->q = 2; var_dump($arr['q'], $ob->q);
