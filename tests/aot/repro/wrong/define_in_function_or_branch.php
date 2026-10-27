<?php
// define() inside a function body or an if-branch leaves the constant undefined for later reads.
// issue: #503
function f() { define("X", 1); }
f();
var_dump(X);
if ($argc > 0) { define("Y", [7]); }
var_dump(Y);
