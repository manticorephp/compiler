<?php

// proc_open() with the three standard pipes: write to the child's stdin, read
// its stdout and stderr, and get its exit status back from proc_close().
//
// php is the oracle here — it has proc_open — so this is an ordinary difftest
// case. The command is `/bin/sh` syntax only, and prints nothing a locale or a
// hostname could change.

$desc = [];
$desc[0] = ['pipe', 'r'];
$desc[1] = ['pipe', 'w'];
$desc[2] = ['pipe', 'w'];

$pipes = [];
$proc = proc_open('cat; echo "to-err" >&2; exit 3', $desc, $pipes);

if ($proc === false) {
    echo "spawn failed\n";
    return;
}

fwrite($pipes[0], "from-stdin\n");
fclose($pipes[0]);

$out = '';
while (($c = fread($pipes[1], 4096)) !== '' && $c !== false) {
    $out .= $c;
}
$err = '';
while (($c = fread($pipes[2], 4096)) !== '' && $c !== false) {
    $err .= $c;
}
fclose($pipes[1]);
fclose($pipes[2]);

$code = proc_close($proc);

echo 'stdout: ', $out;
echo 'stderr: ', $err;
echo 'exit: ', $code, "\n";

// A second spawn, reading only stdout, proves the fds of the first were not
// leaked into it (a leaked write end keeps `cat` from ever seeing EOF).
$desc2 = [];
$desc2[1] = ['pipe', 'w'];
$pipes2 = [];
$p2 = proc_open('printf "second\n"', $desc2, $pipes2);
$out2 = '';
while (($c = fread($pipes2[1], 4096)) !== '' && $c !== false) {
    $out2 .= $c;
}
fclose($pipes2[1]);
echo 'second: ', $out2;
echo 'second exit: ', proc_close($p2), "\n";
