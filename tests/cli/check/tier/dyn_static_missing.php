<?php
class P { public static function make(): int { return missing_fn(); } }
function pick(string $c): int { return $c::make(); }
echo pick($argv[1] ?? ''), "\n";
