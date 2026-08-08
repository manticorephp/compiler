<?php
// A cell holding a FLOAT in integer-shaped arithmetic. The integer path
// unboxes every cell operand with unbox_int, so the fractional value was
// truncated and the result typed int — php promotes to float by the runtime
// tag. Nothing here is statically float, so the existing float path (which a
// float LITERAL operand triggers) never fires.
function addOne(mixed $v)
{
    return $v + 1;
}
var_dump(addOne(1.5));
var_dump(addOne(2));

function scale(mixed $v)
{
    return $v * 2;
}
var_dump(scale(2.5));
var_dump(scale(3));

// Through a cell array element: the value is a float only at runtime.
$a = array_fill(0, 2, 0.5);
$a[0] = $a[0] + 1;
var_dump($a[0]);

$b = array_fill(0, 2, 0);
$b[0] = $b[0] + 1;
var_dump($b[0]);
