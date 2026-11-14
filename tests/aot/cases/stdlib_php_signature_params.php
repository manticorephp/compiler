<?php
// Internal functions take every parameter php declares: filters and ranges
// work (strrchr, strspn, str_word_count, file_get_contents; array_keys directly),
// context/options parameters are accepted, and php's parameter names bind
// named arguments. Direct calls and calls through a run-time name agree.
/** @param mixed[] $a */
function viaName(string $f, array $a): mixed { return $f(...$a); }

$arr = ['a' => 1, 'b' => '1', 'c' => 2, 'd' => 1];
var_dump(array_keys($arr, 1), array_keys($arr, 1, true), array_keys(array: $arr));

var_dump(strrchr('a/b/c', '/'), strrchr('a/b/c', '/', true), viaName('strrchr', ['a/b/c', '/', true]));
var_dump(strspn('42 is the answer', '1234567890'), strspn('foo', 'o', 1, 2), strspn('foo', 'o', -2, 1),
    strspn('aaaa', 'a', 0, -1), viaName('strspn', ['abcd', 'abc', 0, 2]));
var_dump(str_word_count("Hello fri3nd, you're looking good today!", 1, '3'),
    str_word_count('a-b c_d', 0, '_'), viaName('str_word_count', ['x1y z', 2, '1']));
var_dump(hash('crc32b', 'abc', false, []), hash_file('crc32b', __FILE__, false, []) === hash_file('crc32b', __FILE__));

$dir = sys_get_temp_dir() . '/mc_sigparams_' . getmypid();
var_dump(mkdir($dir, 0777, false, null));
$f = $dir . '/f.txt';
var_dump(file_put_contents(filename: $f, data: "0123456789\nline2\n", flags: 0, context: null));
var_dump(file_get_contents($f, false, null, 2, 3), file_get_contents($f, false, null, -6),
    file_get_contents(filename: $f, offset: 11), viaName('file_get_contents', [$f, false, null, 5, 2]));
try {
    file_get_contents($f, false, null, 0, -1);
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump(file($f, 0, null), readfile($f, false, null));
var_dump(copy($f, $f . '.c', null), rename($f . '.c', $f . '.r', null), scandir($dir, 0, null));
$h = opendir($dir, null); var_dump(is_resource($h)); closedir($h);
$fp = fopen($f, 'r', false, null); var_dump(fgets($fp)); fclose($fp);
var_dump(unlink($f, null), unlink($f . '.r', null), rmdir($dir, null));
