<?php
function nc(?int $x): int { return $x ?? 42; }
echo nc(null), nc(5);
