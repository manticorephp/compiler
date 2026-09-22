<?php

// A write to a stdout nobody is reading ends the program, as php's CLI does
// (sapi_cli_ub_write → php_handle_aborted_connection → a bailout that still
// runs the shutdown functions). SIGPIPE is IGNORED on both sides — php's CLI
// ignores it so a half-written buffer is never lost to a signal — so the broken
// pipe arrives as a short WRITE, and noticing it is the whole job. Without
// that, `prog | head` spins for ever.
//
// The case breaks its own stdout by re-executing itself into `| head -2`. The
// child's loop is BOUNDED so neither outcome hangs the suite: a runtime that
// misses the broken pipe finishes the loop, exits 7 and leaves the marker file
// behind; one that notices exits 255 and never reaches either. `PHP_BINARY`
// tells the two runtimes apart — it names the interpreter under php and is
// empty in a compiled binary.

$tmp = rtrim(sys_get_temp_dir(), '/');
$marker = $tmp . '/mc_epipe_marker_' . getmypid();
$status = $tmp . '/mc_epipe_status_' . getmypid();

if (($argv[1] ?? '') === 'child') {
    $n = 0;
    while ($n < 2000000) {
        echo 'x', $n, "\n";
        $n = $n + 1;
    }
    file_put_contents($argv[2], "reached end\n");
    exit(7);
}

@unlink($marker);
@unlink($status);

$self = PHP_BINARY === ''
    ? escapeshellarg($argv[0])
    : escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($argv[0]);
$child = $self . ' child ' . escapeshellarg($marker);

$h = popen('{ ' . $child . '; echo $? > ' . escapeshellarg($status) . '; } | head -2', 'r');
if ($h === false) {
    echo "popen failed\n";
    return;
}
$seen = '';
while (($chunk = fread($h, 64)) !== '') {
    $seen .= $chunk;
}
pclose($h);

echo 'read: ', str_replace("\n", '|', $seen), "\n";
echo 'child exit: ', trim(file_get_contents($status)), "\n";
echo 'reached end: ', file_exists($marker) ? 'yes' : 'no', "\n";

@unlink($marker);
@unlink($status);
echo "done\n";
