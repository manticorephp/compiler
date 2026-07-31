<?php

// php flushes every buffer still open at shutdown, handlers and all. Nothing
// below closes anything — the atexit drain is what makes this print.
function mark(string $b, int $p): string { return "{" . $b . "}"; }

echo "before\n";
ob_start();
echo "level1\n";
ob_start('mark');
echo "level2-handled";
echo "\n";
