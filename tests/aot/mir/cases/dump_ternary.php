<?php
function pick(int $x): int { return $x > 0 ? $x : -$x; }
function shortt(?int $x): int { return $x ?: 7; }
function andor(bool $a, bool $b): bool { return $a && $b || !$a; }
echo pick(-3), shortt(0), andor(true, false);
