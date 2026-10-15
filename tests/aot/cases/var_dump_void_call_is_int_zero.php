<?php
// var_dump() of a void function call prints int(0) instead of NULL.
function f(): void {}
var_dump(f());
