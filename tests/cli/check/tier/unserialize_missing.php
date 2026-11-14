<?php
class Svc { public function run(): int { return missing_fn(); } }
echo unserialize($argv[1] ?? '')->run(), "\n";
