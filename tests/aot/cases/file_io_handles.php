<?php
// File handle (resource) family + file_put/get_contents with FILE_APPEND,
// following php.net semantics. Error suppression `@` is a no-op pass-through.
$path = "/tmp/manticore_io_handles_test.txt";

$f = fopen($path, "w");
if ($f === false) { echo "open-failed\n"; }
$n = fwrite($f, "alpha\n");
$n += fwrite($f, "beta\n");
$n += fwrite($f, "gamma\n");
echo "wrote $n bytes\n";
fclose($f);

$g = fopen($path, "r");
fseek($g, 6, SEEK_SET);
echo "at ", ftell($g), ": ", fread($g, 4), "\n";
rewind($g);
$lines = 0;
while (($line = fgets($g)) !== false) {
    echo "line: ", rtrim($line), "\n";
    $lines++;
}
echo "eof=", feof($g) ? "yes" : "no", " lines=$lines\n";
fclose($g);

file_put_contents($path, "X");
file_put_contents($path, "Y", FILE_APPEND);
file_put_contents($path, "Z", FILE_APPEND);
echo "contents=", file_get_contents($path), "\n";

$bad = @fopen("/no/such/dir/nope.txt", "r");
echo "bad-open=", $bad ? "ok" : "failed", "\n";

unlink($path);
echo "exists=", file_exists($path) ? "yes" : "no", "\n";
