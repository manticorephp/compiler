<?php
// An exception thrown by a function called through an erased callable reports line 0 for the call site in getTrace()
// issue: #598
function boom(mixed $v): never { throw new \RuntimeException('boom'); }
function lines(mixed $f): void {
    try { $f('a'); } catch (\RuntimeException $e) { echo $e->getTrace()[0]['line'] ?? '?', "\n"; }
}
lines('boom');
