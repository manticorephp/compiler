<?php

// stat()/lstat()/fstat() array shape, scandir() ordering, and the flock()
// PHP->OS operation translation. Only fields that cannot vary between hosts or
// filesystems are printed: dev/ino/uid/gid/blksize/blocks are all legitimately
// machine-dependent.

$dir = sys_get_temp_dir() . '/mc_fs_stat_scandir';
if (is_dir($dir)) {
    foreach (scandir($dir) as $e) {
        if ($e !== '.' && $e !== '..') {
            $p = $dir . '/' . $e;
            if (is_dir($p) && !is_link($p)) { rmdir($p); } else { unlink($p); }
        }
    }
    rmdir($dir);
}
mkdir($dir, 0777, true);

file_put_contents($dir . '/b.txt', 'bb');
file_put_contents($dir . '/a.txt', 'a');
// "10" before "9": scandir orders with strcmp, so a numeric-string-aware sort
// would get these two backwards.
file_put_contents($dir . '/10', 'x');
file_put_contents($dir . '/9', 'y');
mkdir($dir . '/sub');
symlink($dir . '/b.txt', $dir . '/lnk');

echo "-- scandir --\n";
echo implode(',', scandir($dir)), "\n";
echo implode(',', scandir($dir, SCANDIR_SORT_DESCENDING)), "\n";
echo count(scandir($dir, SCANDIR_SORT_NONE)), "\n";

echo "-- stat --\n";
$st = stat($dir . '/b.txt');
echo count($st), "\n";
echo $st['size'], ' ', $st[7], "\n";
echo $st['nlink'], "\n";
echo ($st['mode'] & 0170000) === 0100000 ? "reg\n" : "NOT-reg\n";
$sd = stat($dir . '/sub');
echo ($sd['mode'] & 0170000) === 0040000 ? "dir\n" : "NOT-dir\n";
// A failing stat() is deliberately NOT asserted: php emits a Warning, and its
// CLI writes warnings to STDOUT (embedding an absolute path), which would make
// the expected output host-specific.

echo "-- lstat --\n";
$ls = lstat($dir . '/lnk');
echo ($ls['mode'] & 0170000) === 0120000 ? "link\n" : "NOT-link\n";
$sl = stat($dir . '/lnk');
echo ($sl['mode'] & 0170000) === 0100000 ? "reg\n" : "NOT-reg\n";
echo $sl['size'], "\n";

echo "-- fstat --\n";
$fh = fopen($dir . '/b.txt', 'rb');
$fs = fstat($fh);
echo $fs['size'], ' ', $fs[7], "\n";
echo ($fs['mode'] & 0170000) === 0100000 ? "reg\n" : "NOT-reg\n";
fclose($fh);

echo "-- flock --\n";
$fh = fopen($dir . '/lockfile', 'wb');
var_dump(flock($fh, LOCK_EX));
// LOCK_UN is 3 to PHP but 8 to the OS; passing 3 through means LOCK_SH|LOCK_EX,
// which is EINVAL -> the unlock silently fails.
var_dump(flock($fh, LOCK_UN));
var_dump(flock($fh, LOCK_SH));
var_dump(flock($fh, LOCK_UN));
var_dump(flock($fh, LOCK_EX | LOCK_NB));
var_dump(flock($fh, LOCK_UN));
fclose($fh);

unlink($dir . '/lockfile');
unlink($dir . '/lnk');
unlink($dir . '/b.txt');
unlink($dir . '/a.txt');
unlink($dir . '/10');
unlink($dir . '/9');
rmdir($dir . '/sub');
rmdir($dir);
echo "-- done --\n";
