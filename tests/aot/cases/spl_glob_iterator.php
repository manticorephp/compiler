<?php
$d = sys_get_temp_dir() . '/mc_glob_' . getmypid();
mkdir($d);
foreach (['b.txt', 'a.txt', 'c.log', 'd.txt'] as $f) { file_put_contents("$d/$f", $f); }
$g = new GlobIterator("$d/*.txt");
echo count($g), " ", iterator_count($g), "\n";
foreach ($g as $k => $v) {
    echo str_replace($d, 'D', $k), " ", $v->getFilename(), " ", get_class($v), " ", $v->getSize(), "\n";
}
$g = new GlobIterator("$d/*.txt", FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_PATHNAME);
foreach ($g as $k => $v) { echo $k, " ", str_replace($d, 'D', $v), "\n"; }
$g = new GlobIterator("$d/*.none");
echo count($g), "\n";
foreach ($g as $v) { echo "never\n"; }
foreach (glob("$d/*") as $f) { unlink($f); }
rmdir($d);
