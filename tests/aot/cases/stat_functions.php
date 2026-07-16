<?php

// struct stat has no portable layout, so the offsets are picked at runtime from
// uname and self-checked against stat("/"). Everything here is asserted through
// comparisons, never by printing a raw uid/inode — those differ per machine.

$root = sys_get_temp_dir() . '/mc_stat_case';

foreach (['/f.txt', '/lnk', '/big.bin'] as $f) {
    if (file_exists($root . $f)) {
        unlink($root . $f);
    }
}
foreach (['/sub', ''] as $d) {
    if (file_exists($root . $d)) {
        rmdir($root . $d);
    }
}

mkdir($root);
mkdir($root . '/sub');
file_put_contents($root . '/f.txt', "0123456789");
file_put_contents($root . '/big.bin', str_repeat('x', 100000));
symlink($root . '/f.txt', $root . '/lnk');

// Calls on a missing path are omitted: php emits a warning for those and the
// CLI SAPI writes it to stdout, which would land in the expected output.
echo "== filesize ==\n";
var_dump(filesize($root . '/f.txt'));
var_dump(filesize($root . '/big.bin'));

echo "== is_dir / is_file / is_link ==\n";
var_dump(is_dir($root));
var_dump(is_dir($root . '/sub'));
var_dump(is_dir($root . '/f.txt'));
var_dump(is_dir($root . '/missing'));
var_dump(is_file($root . '/f.txt'));
var_dump(is_file($root));
var_dump(is_file($root . '/missing'));
var_dump(is_link($root . '/lnk'));
var_dump(is_link($root . '/f.txt'));
var_dump(is_dir('/'));
var_dump(is_dir('/tmp'));

echo "== is_link follows nothing, is_file follows the link ==\n";
var_dump(is_file($root . '/lnk'));

echo "== filetype ==\n";
var_dump(filetype($root));
var_dump(filetype($root . '/f.txt'));
var_dump(filetype($root . '/lnk'));

echo "== fileperms ==\n";
chmod($root . '/f.txt', 0644);
var_dump(fileperms($root . '/f.txt') & 0777);
chmod($root . '/f.txt', 0600);
var_dump(fileperms($root . '/f.txt') & 0777);
chmod($root . '/f.txt', 0755);
var_dump(fileperms($root . '/f.txt') & 0777);
chmod($root . '/f.txt', 0644);

echo "== filemtime / fileatime / filectime ==\n";
touch($root . '/f.txt', 1000000000, 1000000001);
var_dump(filemtime($root . '/f.txt'));
var_dump(fileatime($root . '/f.txt'));
var_dump(filectime($root . '/f.txt') > 0);

echo "== fileowner / filegroup / fileinode ==\n";
var_dump(fileowner($root . '/f.txt') === fileowner($root . '/big.bin'));
var_dump(filegroup($root . '/f.txt') === filegroup($root . '/big.bin'));
var_dump(fileowner($root . '/f.txt') >= 0);
var_dump(fileinode($root . '/f.txt') > 0);
var_dump(fileinode($root . '/f.txt') !== fileinode($root . '/big.bin'));

echo "== hard link shares an inode ==\n";
link($root . '/f.txt', $root . '/hard.txt');
var_dump(fileinode($root . '/f.txt') === fileinode($root . '/hard.txt'));
unlink($root . '/hard.txt');

echo "== opendir / readdir / closedir ==\n";
$d = opendir($root);
var_dump($d !== false);
$names = [];
while (($e = readdir($d)) !== false) {
    $names[] = $e;
}
closedir($d);
sort($names);
var_dump($names);

echo "== rewinddir ==\n";
$d = opendir($root);
$first = [];
while (($e = readdir($d)) !== false) {
    $first[] = $e;
}
rewinddir($d);
$second = [];
while (($e = readdir($d)) !== false) {
    $second[] = $e;
}
closedir($d);
sort($first);
sort($second);
// Compared as joined strings, not with ===: a strict compare of two arrays
// currently compares buffer pointers rather than contents, which is a separate
// bug and has nothing to do with rewinddir.
var_dump(implode(',', $first) === implode(',', $second));
var_dump(count($first));

// scandir is not covered: not shipped yet (see the note in Stat.php).
echo "== cleanup ==\n";
unlink($root . '/lnk');
unlink($root . '/big.bin');
unlink($root . '/f.txt');
rmdir($root . '/sub');
rmdir($root);
var_dump(file_exists($root));
