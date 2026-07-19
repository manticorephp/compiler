<?php

// socket_create_pair: a connected AF_UNIX pair, data flows both ways. Fully
// self-contained (no port, no external peer), so it is deterministic offline.

// NOTE: `$pair[N] instanceof Socket` is deliberately NOT asserted. The two
// Socket objects flow back through a by-ref `array &$pair` from the stdlib, and
// a stdlib-built object array crosses the module boundary with its elements
// UNBOXED — so element introspection (instanceof / gettype) reads them wrong,
// while every OPERATION on them works (the elements coerce back to \Socket at
// each socket_* call, so read/write below are correct). That element-boxing gap
// is the representation-consistency epic, tracked separately.
$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
var_dump($ok);
var_dump(count($pair));

socket_write($pair[0], "ping");
$a = '';
socket_recv($pair[1], $a, 4, 0);
var_dump($a);

socket_write($pair[1], "pong");
$b = '';
socket_recv($pair[0], $b, 4, 0);
var_dump($b);

socket_close($pair[0]);
socket_close($pair[1]);
