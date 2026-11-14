<?php
class Svc { public function run(): int { return 1; } }
class Other { public function go(): int { return 2; } }
echo unserialize($argv[1] ?? '')->run(), "\n";
