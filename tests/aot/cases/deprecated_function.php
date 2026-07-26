<?php

// #[\Deprecated] on free functions: bare, since only, message only, both — and
// through a constant-folded dynamic call.

#[\Deprecated]
function bare(): int { return 1; }

#[\Deprecated(since: "1.5")]
function sinceOnly(): int { return 2; }

#[\Deprecated("use bare() instead")]
function messageOnly(): int { return 3; }

#[\Deprecated(message: "use bare() instead", since: "1.5")]
function both(): int { return 4; }

function fine(): int { return 5; }

echo "start\n";
echo bare(), "\n";
echo sinceOnly(), "\n";
echo messageOnly(), "\n";
echo both(), "\n";
echo fine(), "\n";
$dyn = 'bare';
echo $dyn(), "\n";
echo "end\n";
