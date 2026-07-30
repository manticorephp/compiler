<?php
// A top-level `return` ENDS the script but does NOT set the exit status — php CLI
// leaves $? at 0 (only exit()/die() and an uncaught throw set it). The value is the
// include-return the entry hands its includer, and a main script has none.
// symfony's entry idiom is `return $app->run();` with setAutoExit(false): php exits
// 0 from it, and manticore reported the command's status instead.
// The runner fails any case with rc != 0, so this case IS the assertion.

function status(): int { echo "ran\n"; return 3; }

echo "before\n";
return status();
echo "unreachable\n";
