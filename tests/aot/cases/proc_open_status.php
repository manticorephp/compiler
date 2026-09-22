<?php

// proc_get_status() / proc_terminate() over a live child: running before the
// signal, signalled after it, and the signal number reported back. php is the
// oracle — an ordinary difftest case.
$d = [1 => ['pipe', 'w']];
$p = [];
$h = proc_open('sleep 2; exit 5', $d, $p);
$s1 = proc_get_status($h);
echo 'running: ', $s1['running'] ? 'yes' : 'no', "\n";
echo 'pid>0: ', $s1['pid'] > 0 ? 'yes' : 'no', "\n";
proc_terminate($h, 9);
usleep(300000);
$s2 = proc_get_status($h);
echo 'after term running: ', $s2['running'] ? 'yes' : 'no', "\n";
echo 'signaled: ', $s2['signaled'] ? 'yes' : 'no', "\n";
echo 'termsig: ', $s2['termsig'], "\n";
fclose($p[1]);
proc_close($h);
echo "done\n";
