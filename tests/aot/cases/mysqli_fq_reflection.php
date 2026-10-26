<?php
// new \ReflectionClass on a mysqli name spelled as a string literal links the extension.
$r = new \ReflectionClass('mysqli_driver');
var_dump($r->getName(), $r->isFinal());
