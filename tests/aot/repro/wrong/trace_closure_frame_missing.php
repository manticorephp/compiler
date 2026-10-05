<?php
// A closure that throws has no frame in getTrace(): php reports it, the binary skips it.
// issue: #80
function run() { $f = function () { throw new Exception("x"); }; $f(); }
try { run(); } catch (Exception $e) { echo count($e->getTrace()), "\n"; }
