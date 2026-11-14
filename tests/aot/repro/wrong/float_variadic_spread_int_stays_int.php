<?php
// Int elements spread into a `float ...$xs` variadic stay int instead of converting to float.
function w(float ...$xs): array { return $xs; }
$a = [1, 2];
var_dump(w(...$a));
