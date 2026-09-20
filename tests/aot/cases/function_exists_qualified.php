<?php
// A qualified literal is an FQN check, never a bare-name one: this user
// contextSwitch must not make the scheduler's Manticore\Sapi guard fold true.
function contextSwitch(int $from, int $to): void { echo "USER $from->$to\n"; }
echo function_exists('Manticore\\Sapi\\contextSwitch') ? 'y' : 'n';
echo function_exists('contextSwitch') ? 'y' : 'n';
echo function_exists('No\\Such\\fn') ? 'y' : 'n', "\n";
\Async\async(function () {
    \Async\spawn(function () { echo "b"; });
    echo "a";
});
echo "\n";
