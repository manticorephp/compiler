<?php

// Built-in classes compare as php's do, whatever private state this
// implementation keeps: a HashContext and a Fiber have no properties (equal
// within the class), a DeflateContext / InflateContext / CurlHandle is
// uncomparable (identity only, <=> 1).

// Through a `mixed` hop: hash_init()'s declared HashContext is a stdlib class
// no user module sees, so its DIRECT return is an erased word (compared raw —
// the erased-channel gap, logged); the hop reaches the runtime compare.
function m(mixed $v): mixed { return $v; }
$h1 = m(hash_init('sha256'));
$h2 = m(hash_init('sha256'));
hash_update($h2, 'x');
var_dump($h1 == $h2, $h1 <=> $h2, $h1 == m(hash_init('md5')), $h1 == $h1);

$d1 = deflate_init(ZLIB_ENCODING_RAW);
$d2 = deflate_init(ZLIB_ENCODING_RAW);
var_dump($d1 == $d2, $d1 <=> $d2, $d2 <=> $d1, $d1 == $d1, $d1 < $d2, $d1 > $d2);

$i1 = inflate_init(ZLIB_ENCODING_DEFLATE);
$i2 = inflate_init(ZLIB_ENCODING_DEFLATE);
var_dump($i1 == $i2, $i1 <=> $i2, $i1 == $i1);

$f1 = new Fiber(fn() => 1);
$f2 = new Fiber(fn() => 2);
var_dump($f1 == $f2, $f1 <=> $f2);

$c1 = curl_init('http://a.invalid/');
$c2 = curl_init('http://a.invalid/');
var_dump($c1 == $c2, $c1 <=> $c2, $c1 == $c1);
