<?php
// An error raised inside a builtin (intdiv) has no frame for the builtin in getTrace().
function v() { return intdiv(1, 0); }
try { v(); } catch (Throwable $e) { foreach ($e->getTrace() as $fr) { echo $fr['function'], "\n"; } }
