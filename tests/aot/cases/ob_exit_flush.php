<?php

// exit() runs atexit handlers before libc drains stdio, so an open buffer still
// reaches stdout — php's behaviour too.
echo "start\n";
ob_start();
echo "buffered-through-exit\n";
exit(0);
