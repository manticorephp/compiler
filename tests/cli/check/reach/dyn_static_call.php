<?php
class P { public static function make(): int { return 1; } }
class Q { public static function make(): int { return 2; } }
function pick(string $c): int { return $c::make(); }
echo pick($argv[1] ?? ''), "\n";
