<?php
// CLI superglobals + getopt + standard streams.
echo \count($argv) >= 1 ? "argv-ok\n" : "argv-bad\n";
echo $argc >= 1 ? "argc-ok\n" : "argc-bad\n";
\fwrite(STDOUT, "stdout-line\n");
\fwrite(STDERR, "stderr-line\n");
$o = getopt("v", ["help"]);
echo \count($o), "\n";
echo \is_array($o) ? "getopt-array\n" : "getopt-bad\n";
