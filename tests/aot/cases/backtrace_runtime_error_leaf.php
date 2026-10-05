<?php
function viaMod() { return 1 % 0; }
function viaNested() { return viaMod(); }
foreach (['viaMod', 'viaNested'] as $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        echo get_class($e), ':';
        foreach ($e->getTrace() as $fr) { echo ' ', $fr['function']; }
        echo "\n";
    }
}
