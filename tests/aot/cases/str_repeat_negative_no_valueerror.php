<?php
// str_repeat() with a negative count returns "" instead of throwing ValueError.
try { var_dump(str_repeat('x', -1)); } catch (Throwable $e) { echo get_class($e), "\n"; }
