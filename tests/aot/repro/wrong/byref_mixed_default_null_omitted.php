<?php
// An omitted `mixed &$v = null` argument reads as a non-null junk value instead of null.
// issue: #415
function f(mixed &$v = null) { var_dump($v !== null); var_dump($v); }
f();
class B { function __construct(mixed &$v = null) { var_dump($v !== null); } }
new B;
