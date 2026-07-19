<?php

// ext/sockets handle is php 8's opaque Socket OBJECT, not a resource. This locks
// in the type introspection: is_resource false, gettype "object",
// get_debug_type "Socket" — all byte-identical to php.

$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
var_dump($s instanceof Socket);
var_dump(is_object($s));
var_dump(is_resource($s));
var_dump(gettype($s));
var_dump(get_debug_type($s));
socket_close($s);

$u = socket_create(AF_UNIX, SOCK_STREAM, 0);
var_dump($u instanceof Socket);
socket_close($u);
