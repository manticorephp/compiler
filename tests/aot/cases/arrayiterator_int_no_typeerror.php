<?php
// new ArrayIterator(1) does not throw TypeError.
try { new ArrayIterator(1); echo "noerr\n"; } catch (Throwable $e) { echo get_class($e), "\n"; }
