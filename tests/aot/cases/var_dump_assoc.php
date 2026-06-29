<?php
// var_dump of a homogeneous-valued assoc — boxToCell must element-box the
// entries AND render real keys. Was a SIGSEGV (raw entries read as cells).
var_dump(["a" => 1, "b" => 2]);
var_dump(["x" => "p", "y" => "q"]);
var_dump([1.5, 2.5]);
var_dump([1, 2, 3]);
var_dump(["a" => 1, "b" => "y"]);
$d = ["k" => 10]; var_dump($d);

// a `mixed`-param container (the SPL pattern): the arg is boxed at the call,
// so a homogeneous backing array round-trips through getArrayCopy().
$it = new ArrayIterator(["one" => 1, "two" => 2]);
var_dump($it->getArrayCopy());
