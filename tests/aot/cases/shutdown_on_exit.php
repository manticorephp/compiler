<?php

// The shutdown queue must run on exit() too, not only on falling off the end.

register_shutdown_function(function (): void { echo "shutdown ran\n"; });
echo "before exit\n";
exit(0);
echo "NEVER\n";
