<?php
// stream_select over a FILE*-backed stream must poll the real fd, not the FILE* address.
$f = fopen(__FILE__, 'r');
$r = [$f]; $w = null; $e = null;
var_dump(stream_select($r, $w, $e, 0));
var_dump(count($r));
