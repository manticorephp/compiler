<?php
/** @param array{get: ?string, set: ?string} $h */
function g(array $h): string { return ($h['get'] ?? 'x') . ($h['set'] ?? 'y'); }
echo g(['get' => null, 'set' => 's']), "\n";
echo g(['get' => 'a', 'set' => null]), "\n";
