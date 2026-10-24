<?php
// is_resource() of a mixed holding a denormal float dereferences the float's bits as a pointer (SIGSEGV)
function f(mixed $v): bool { return is_resource($v); }
var_dump(f(1.5));
var_dump(f(1e-310));
var_dump(f(5e-324));
