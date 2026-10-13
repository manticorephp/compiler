<?php
// var_dump() of a void function call prints int(0) instead of NULL.
// issue: #235
function f(): void {}
var_dump(f());
