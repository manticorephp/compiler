<?php

// glob() over libc glob(3). php carries its OWN glob since 8.3, so its GLOB_*
// are php's values, not the host header's — GLOB_NOESCAPE is 0x1000 where
// Darwin says 0x2000, and GLOB_ONLYDIR (0x40000000) exists in no libc. The
// flags are therefore translated, never passed through. Exercised by NAME so a
// wrong translation shows up as a behaviour difference, not a magic number.

$dir = sys_get_temp_dir() . '/mc_glob_case';
if (is_dir($dir)) {
    foreach (scandir($dir) as $e) {
        if ($e !== '.' && $e !== '..') {
            $p = $dir . '/' . $e;
            if (is_dir($p)) { rmdir($p); } else { unlink($p); }
        }
    }
    rmdir($dir);
}
mkdir($dir, 0777, true);
foreach (['a.txt', 'b.txt', 'c.log', '10.txt', '9.txt'] as $f) {
    file_put_contents($dir . '/' . $f, 'x');
}
mkdir($dir . '/sub');

function names(array $paths): string
{
    $out = [];
    foreach ($paths as $p) { $out[] = basename($p); }
    return implode(',', $out);
}

echo "star:    ", names(glob($dir . '/*')), "\n";
echo "txt:     ", names(glob($dir . '/*.txt')), "\n";
echo "log:     ", names(glob($dir . '/*.log')), "\n";
echo "q:       ", names(glob($dir . '/?.txt')), "\n";
echo "brace:   ", names(glob($dir . '/*.{txt,log}', GLOB_BRACE)), "\n";
echo "onlydir: ", names(glob($dir . '/*', GLOB_ONLYDIR)), "\n";
echo "mark:    ", names(glob($dir . '/su*', GLOB_MARK)), "\n";
echo "nosort:  ", count(glob($dir . '/*', GLOB_NOSORT)), "\n";
echo "nomatch: ", count(glob($dir . '/zzz*')), "\n";
echo "nocheck: ", names(glob($dir . '/zzz*', GLOB_NOCHECK)), "\n";
// glob returns full paths, not bare names.
$all = glob($dir . '/*.log');
echo "fullpath: ", ($all[0] === $dir . '/c.log') ? "yes" : "no", "\n";

foreach (['a.txt', 'b.txt', 'c.log', '10.txt', '9.txt'] as $f) {
    unlink($dir . '/' . $f);
}
rmdir($dir . '/sub');
rmdir($dir);
echo "-- done --\n";
