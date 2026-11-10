<?php
// strrev() called with a spread argument fails to compile.
// issue: #644
/** @param string[] $c */
function f(array $c): string { return strrev(...$c); }
echo f(["abc"]), "\n";
