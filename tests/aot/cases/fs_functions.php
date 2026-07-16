<?php

// Everything happens under one scratch dir the case creates and removes, so
// the output is identical under php and native regardless of disk state.
$root = sys_get_temp_dir() . '/mc_fs_case';

// Clean leftovers from an aborted run. Files first, then directories deepest
// first, every call guarded by file_exists — an unguarded rmdir/unlink emits a
// php warning, and under the CLI SAPI that lands on stdout and would corrupt
// the expected output.
foreach (['/f.txt', '/copy.txt', '/renamed.txt', '/lnk', '/hard.txt', '/touched', '/lines.txt', '/trunc.txt'] as $f) {
    if (file_exists($root . $f)) {
        unlink($root . $f);
    }
}
foreach (['/a/b/c/d/e', '/a/b/c/d', '/a/b/c', '/a/b', '/a', ''] as $d) {
    if (file_exists($root . $d)) {
        rmdir($root . $d);
    }
}

echo "== mkdir ==\n";
var_dump(mkdir($root));
var_dump(is_writable($root));
var_dump(mkdir($root . '/a/b/c', 0777, true));
var_dump(mkdir($root . '/a/b/c/d/e', 0777, true));

echo "== write + copy ==\n";
file_put_contents($root . '/f.txt', "hello\nworld\n");
var_dump(copy($root . '/f.txt', $root . '/copy.txt'));
var_dump(file_get_contents($root . '/copy.txt'));

echo "== rename ==\n";
var_dump(rename($root . '/copy.txt', $root . '/renamed.txt'));
var_dump(file_exists($root . '/copy.txt'));
var_dump(file_exists($root . '/renamed.txt'));
var_dump(rename($root . '/renamed.txt', $root . '/copy.txt'));

echo "== symlink / readlink ==\n";
var_dump(symlink($root . '/f.txt', $root . '/lnk'));
var_dump(readlink($root . '/lnk') === $root . '/f.txt');

echo "== link ==\n";
var_dump(link($root . '/f.txt', $root . '/hard.txt'));
var_dump(file_get_contents($root . '/hard.txt'));

echo "== realpath ==\n";
var_dump(realpath($root . '/a/../f.txt') === realpath($root . '/f.txt'));
var_dump(realpath($root . '/f.txt') !== false);

echo "== chmod / access ==\n";
var_dump(chmod($root . '/f.txt', 0400));
var_dump(is_writable($root . '/f.txt'));
var_dump(is_executable($root . '/f.txt'));
var_dump(chmod($root . '/f.txt', 0644));
var_dump(is_writable($root . '/f.txt'));
var_dump(chmod($root . '/f.txt', 0755));
var_dump(is_executable($root . '/f.txt'));
var_dump(chmod($root . '/f.txt', 0644));

echo "== touch ==\n";
var_dump(touch($root . '/touched'));
var_dump(file_exists($root . '/touched'));
var_dump(touch($root . '/touched', 1000000000));
var_dump(touch($root . '/touched', 1000000000, 1000000001));

echo "== file() ==\n";
file_put_contents($root . '/lines.txt', "a\nb\n\nc");
var_dump(file($root . '/lines.txt'));
var_dump(file($root . '/lines.txt', FILE_IGNORE_NEW_LINES));
var_dump(file($root . '/lines.txt', FILE_SKIP_EMPTY_LINES));
var_dump(file($root . '/lines.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

echo "== readfile ==\n";
var_dump(readfile($root . '/lines.txt'));

echo "== fgetc ==\n";
$fh = fopen($root . '/f.txt', 'rb');
var_dump(fgetc($fh));
var_dump(fgetc($fh));
fclose($fh);

echo "== ftruncate / flock / fsync ==\n";
file_put_contents($root . '/trunc.txt', "0123456789");
$fh = fopen($root . '/trunc.txt', 'r+b');
var_dump(flock($fh, LOCK_EX));
var_dump(ftruncate($fh, 4));
var_dump(fsync($fh));
var_dump(flock($fh, LOCK_UN));
fclose($fh);
var_dump(file_get_contents($root . '/trunc.txt'));

echo "== umask ==\n";
$old = umask();
var_dump(is_int($old));
var_dump(umask(0022) === $old);
var_dump(umask($old) === 0022);
var_dump(umask() === $old);

echo "== sys_get_temp_dir ==\n";
var_dump(strlen(sys_get_temp_dir()) > 0);
var_dump(substr(sys_get_temp_dir(), -1) === '/');

echo "== clearstatcache ==\n";
clearstatcache();
echo "ok\n";

echo "== cleanup ==\n";
var_dump(unlink($root . '/lnk'));
var_dump(unlink($root . '/hard.txt'));
var_dump(unlink($root . '/touched'));
var_dump(unlink($root . '/lines.txt'));
var_dump(unlink($root . '/trunc.txt'));
var_dump(unlink($root . '/copy.txt'));
var_dump(unlink($root . '/f.txt'));
var_dump(rmdir($root . '/a/b/c/d/e'));
var_dump(rmdir($root . '/a/b/c/d'));
var_dump(rmdir($root . '/a/b/c'));
var_dump(rmdir($root . '/a/b'));
var_dump(rmdir($root . '/a'));
var_dump(rmdir($root));
var_dump(file_exists($root));
