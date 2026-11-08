<?php
// fopen's x, x+, c and c+ modes (Symfony's cache writes through 'x').
$d = sys_get_temp_dir() . '/mcfo' . getmypid() . '_' . mt_rand(); mkdir($d);
$f = "$d/a b+c=.tmp";
$h = fopen($f, 'x'); var_dump(is_resource($h)); fwrite($h, 'hello'); fclose($h);
var_dump(@fopen($f, 'x')); var_dump(@fopen($f, 'x+'));
echo file_get_contents($f), "\n";
$h = fopen($f, 'c'); fwrite($h, 'J'); fclose($h); echo file_get_contents($f), "\n";
$h = fopen($f, 'c+'); echo fread($h, 2), "\n"; fseek($h, 0, SEEK_END); fwrite($h, '!'); fclose($h); echo file_get_contents($f), "\n";
$g = "$d/new"; $h = fopen($g, 'c'); fwrite($h, 'n'); fclose($h); echo file_get_contents($g), "\n";
$p = "$d/plus"; $h = fopen($p, 'x+'); fwrite($h, 'abc'); rewind($h); echo fread($h, 3), "\n"; fclose($h);
$b = "$d/bin"; $h = fopen($b, 'xb'); fclose($h); var_dump(is_file($b));
var_dump(@fopen("$d/none/x", 'x'), @fopen("$d/none/x", 'c'));
foreach ([$f, $g, $p, $b] as $u) { unlink($u); } rmdir($d);
