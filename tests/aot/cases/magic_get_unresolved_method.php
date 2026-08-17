<?php

// A direct magic-method-shaped call on an erased receiver must never become a
// bare @manticore___get LLVM symbol.  The receiver is intentionally absent at
// runtime: PHP throws only if this branch is reached, while AOT must still
// assemble the program.
function unresolved_magic_call(mixed $value, string $name): mixed
{
    return $value->__get($name);
}

// Keep the function compiled but do not execute PHP's runtime Error path.
var_dump('ok');
