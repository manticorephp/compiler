<?php
// The object returned by a closure call whose result is discarded (`$k();`) is not released at the statement: its destructor runs at shutdown
// issue: #97
final class P { public function __destruct() { echo "dtor\n"; } }
$k = function (): P { return new P(); };
$k();
echo "after call\n";
