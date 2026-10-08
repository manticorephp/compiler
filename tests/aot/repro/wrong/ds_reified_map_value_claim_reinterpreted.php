<?php
// A bound Map<string,int> reinterprets a stored value that does not fit V when it is read back by a matching key.
// issue: #137
use Manticore\Ds\Map;
/** @var Map<string,int> $m */
$m = new Map();
$m->set("a", "x");
$m->set("b", 2.5);
var_dump($m->get("a"));
var_dump($m["b"]);
