<?php

// fnmatch() flag passthrough, chdir/getcwd, and the tempnam/tmpfile pair.
// FNM_* are deliberately exercised by NAME: their values differ between Darwin
// and glibc, so a hardcoded number here would pass on one host and fail on the
// other.

echo "-- fnmatch --\n";
var_dump(fnmatch('*.txt', 'a.txt'));
var_dump(fnmatch('*.txt', 'a.php'));
var_dump(fnmatch('a?c', 'abc'));
var_dump(fnmatch('a?c', 'abbc'));
var_dump(fnmatch('[abc]x', 'bx'));
var_dump(fnmatch('[abc]x', 'dx'));
var_dump(fnmatch('*', 'anything'));
var_dump(fnmatch('A*', 'about', FNM_CASEFOLD));
var_dump(fnmatch('A*', 'about'));
var_dump(fnmatch('*/b', 'a/b', FNM_PATHNAME));
var_dump(fnmatch('*', 'a/b', FNM_PATHNAME));

echo "-- chdir/getcwd --\n";
$start = getcwd();
$tmp = sys_get_temp_dir();
var_dump(chdir($tmp));
var_dump(getcwd() === realpath($tmp));
var_dump(chdir($start));
var_dump(getcwd() === $start);
// A failing chdir() is not asserted: php warns, and its CLI sends warnings to
// STDOUT with an absolute path, which would make this expected output
// host-specific.

echo "-- tempnam --\n";
$p = tempnam(sys_get_temp_dir(), 'mcT');
var_dump(is_string($p));
var_dump(file_exists($p));
var_dump(strpos(basename($p), 'mcT') === 0);
file_put_contents($p, 'hello');
echo file_get_contents($p), "\n";
$st = stat($p);
echo $st['size'], "\n";
unlink($p);
var_dump(file_exists($p));

echo "-- tmpfile --\n";
$fh = tmpfile();
var_dump($fh !== false);
fwrite($fh, "line one\n");
fwrite($fh, "line two\n");
rewind($fh);
echo fgets($fh);
echo fgets($fh);
var_dump(feof($fh) === false);
$fs = fstat($fh);
echo $fs['size'], "\n";
fclose($fh);
echo "-- done --\n";
