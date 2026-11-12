<?php
// Int arguments collected by a `float ...$xs` variadic stay int instead of converting to float.
function v(float ...$xs): array { return $xs; }
var_dump(v(5, 2));
