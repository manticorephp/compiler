<?php

// headers_sent() takes two by-REFERENCE out-parameters. They are the reason the
// call compiles at all: an argument in a by-ref position is a DEFINITION, so a
// caller writing `headers_sent($file, $line)` against a zero-parameter
// declaration left $file dangling and the whole program was refused
// (symfony/http-foundation NativeSessionStorage::start).
//
// Every call happens BEFORE any output, where php's answer is fixed: false,
// '' and 0. Once output has started php reports the position it started at,
// which is the source path — not something an expectation file can pin.

$sent = headers_sent($file, $line);

$f2 = 'x';                 // an explicitly initialised local takes the same path
$l2 = 99;
$sent2 = headers_sent($f2, $l2);

$bare = headers_sent();    // no arguments at all, as most callers write it

var_dump($sent, $file, $line);
var_dump($sent2, $f2, $l2);
var_dump($bare);
