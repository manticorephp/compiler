<?php
// Binary-safe string ops on embedded-NUL data: substr / strtoupper / str_repeat
// read the header length (not C strlen), and file I/O round-trips raw bytes.
$s = "head" . chr(0) . "mid" . chr(0) . "tail";
echo strlen($s), "\n";                    // 13
echo strlen(substr($s, 4, 5)), "\n";      // 5
echo strlen(strtoupper($s)), "\n";        // 13
echo strlen(str_repeat($s, 3)), "\n";     // 39
echo ord(substr($s, 4, 1)), "\n";         // 0 (the NUL survives)
$f = "/tmp/mant_binsafe.dat";
file_put_contents($f, $s);
$r = file_get_contents($f);
echo strlen($r), "\n";                    // 13
echo ($r === $s) ? "roundtrip-ok\n" : "roundtrip-bad\n";
