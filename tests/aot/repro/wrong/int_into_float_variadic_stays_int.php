<?php
// Int arguments collected by a `float ...$xs` variadic stay int instead of converting to float.
// issue: #554
function v(float ...$xs): array { return $xs; }
var_dump(v(5, 2));
