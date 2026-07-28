<?php
// A file that cannot report its length must still be read: procfs and sysfs say
// st_size 0 and hand out their contents only on read, so sizing the buffer from
// ftell(SEEK_END) returned the empty string — which reads as "the file is empty",
// not as a failure. php is the oracle on both hosts; the answer differs per host
// only because Darwin has no procfs.

$p = '/proc/self/status';
if (!is_file($p)) {
    echo "no procfs\n";
} else {
    $s = file_get_contents($p);
    var_dump($s !== false && strlen($s) > 64, str_contains((string)$s, 'VmSize'));
    // The same path through fopen/fread, which is what the growing read uses.
    $fh = fopen($p, 'rb');
    $all = stream_get_contents($fh);
    fclose($fh);
    var_dump(strlen($all) > 64);
}
