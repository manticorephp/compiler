<?php

// A stdlib by-ref out-param fills an array with OBJECTS. The caller must see
// self-describing cell elements: instanceof / gettype / is_object must match php.
$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
if (!$ok) { echo "create_pair failed\n"; exit(1); }

echo "count ", count($pair), "\n";
echo "is_object[0] ", var_export(is_object($pair[0]), true), "\n";
echo "is_object[1] ", var_export(is_object($pair[1]), true), "\n";
echo "instanceof[0] ", var_export($pair[0] instanceof Socket, true), "\n";
echo "instanceof[1] ", var_export($pair[1] instanceof Socket, true), "\n";
echo "gettype[0] ", gettype($pair[0]), "\n";
echo "type[0] ", get_debug_type($pair[0]), "\n";

socket_close($pair[0]);
socket_close($pair[1]);
echo "done\n";
