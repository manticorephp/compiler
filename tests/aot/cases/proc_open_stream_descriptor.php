<?php
// A stream resource as a descriptor-spec entry receives the child's fd 2: the
// shell diagnostic lands in the file, not on this process's stderr.
$f = tmpfile();
$p = proc_open('nosuchcmd_mc_x; echo out', [['pipe', 'rb'], ['pipe', 'wb'], $f], $pipes);
fclose($pipes[0]);
echo "stdout=[", stream_get_contents($pipes[1]), "]\n";
fclose($pipes[1]);
echo "exit=", proc_close($p), "\n";
rewind($f);
echo "stderr has diagnostic: ", str_contains(stream_get_contents($f), 'nosuchcmd_mc_x') ? 'yes' : 'no', "\n";
fclose($f);
