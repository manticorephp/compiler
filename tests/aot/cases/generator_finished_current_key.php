<?php
// A finished generator answers current() / key() NULL whatever its element
// type (an int one read int(0) and its last key), and next() / send() on a
// generator nobody started run it to its first yield first. Zend oracle.
/** @return Generator<int,int> */
function gi(): Generator { yield 5; yield 6; }
function gs(): Generator { yield 'a' => 'x'; }
function gf(): Generator { yield 1.5; }
function gb(): Generator { yield true; }
function gob(): Generator { yield new stdClass(); }
$g = gi(); foreach ($g as $v) {} var_dump($g->current(), $g->key(), $g->valid());
$g = gi(); $g->next(); $g->next(); var_dump($g->current(), $g->key());
$g = gs(); foreach ($g as $v) {} var_dump($g->current(), $g->key());
$g = gf(); foreach ($g as $v) {} var_dump($g->current());
$g = gb(); foreach ($g as $v) {} var_dump($g->current());
$g = gob(); foreach ($g as $v) {} var_dump($g->current());
$g = gi(); var_dump($g->current(), $g->key(), $g->send(9), $g->send(9), $g->key());
function echoer(): Generator { $got = yield 1; echo 'got ', $got, "\n"; yield 2; }
$g = echoer(); var_dump($g->send('s'), $g->current());
$g = gi(); $g->next(); var_dump($g->current(), $g->key());
