<?php
// A literal passed to a by-reference parameter through an erased callable corrupts the closure's return value instead of throwing "could not be passed by reference"
// issue: #597
function g(mixed $f): void {
    try { $r = $f(strtoupper('a')); echo "ret ", var_export($r, true), "\n"; } catch (\Throwable $e) { echo get_class($e), "\n"; }
    try { $r = $f(1); echo "ret ", var_export($r, true), "\n"; } catch (\Throwable $e) { echo get_class($e), ': ', \preg_replace('/\{closure[^}]*\}/', '{closure}', $e->getMessage()), "\n"; }
}
g(function (&$x) { $x = 5; return 'closure'; });
