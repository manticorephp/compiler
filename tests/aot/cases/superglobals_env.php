<?php
// $_ENV is a KNOWN, deliberate divergence from stock php: the interpreter's
// default variables_order ("GPCS") leaves $_ENV empty and puts the environment
// in $_SERVER only. A native binary has no php.ini to flip, and the environment
// is the only configuration it gets, so manticore populates $_ENV as php does
// with variables_order=EGPCS. See tools/difftest.sh is_known_divergence.
echo $_ENV['HOME'] === getenv('HOME') ? "env=getenv\n" : "env!=getenv\n";
echo count($_ENV) > 0 ? "populated\n" : "empty\n";
// $_ENV and $_SERVER agree on the environment they both carry.
echo $_ENV['PATH'] === $_SERVER['PATH'] ? "agree\n" : "differ\n";
