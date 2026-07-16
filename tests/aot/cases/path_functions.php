<?php

$paths = [
    '/foo/bar/baz.txt', '/foo/bar/', '/foo/bar', '/foo', 'foo', 'foo/bar',
    '/', '', '.', '..', './x', 'a//b', '/a//b//', '.hidden', 'x.tar.gz',
    '/foo/.hidden', 'noext', '/a/b/noext', '//', '///a', 'a.', 'a/.',
    '/foo/bar.txt/', '  /sp ace/f.txt', 'a/b/c/d/e.f.g',
];

echo "== basename ==\n";
foreach ($paths as $p) {
    echo var_export($p, true), ' => ', var_export(basename($p), true), "\n";
}

echo "== basename suffix ==\n";
$sfx = [
    ['foo.txt', '.txt'], ['foo.txt', 'foo.txt'], ['foo.txt', '.xt'],
    ['/a/b.tar.gz', '.gz'], ['x.php', '.php'], ['.php', '.php'],
    ['bar', 'bar'], ['/a/bar', 'bar'], ['abc', 'abcd'], ['', ''],
];
foreach ($sfx as $pair) {
    echo var_export($pair[0], true), ' , ', var_export($pair[1], true),
        ' => ', var_export(basename($pair[0], $pair[1]), true), "\n";
}

echo "== dirname ==\n";
foreach ($paths as $p) {
    echo var_export($p, true), ' => ', var_export(dirname($p), true), "\n";
}

echo "== dirname levels ==\n";
foreach (['/a/b/c', 'a/b', '/a', 'a', '/a/b/c/d'] as $p) {
    echo var_export($p, true), ' L2 => ', var_export(dirname($p, 2), true), "\n";
}
echo var_export(dirname('/a/b/c/d', 3), true), "\n";
echo var_export(dirname('/a/b/c/d', 9), true), "\n";

echo "== dirname levels error ==\n";
try {
    dirname('/a/b', 0);
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "== pathinfo all ==\n";
foreach (['/www/htdocs/inc/lib.inc.php', '/foo/bar', 'noext', '.hidden', '/a/', 'x.', '/', ''] as $p) {
    $i = pathinfo($p);
    echo var_export($p, true), ' => ';
    $parts = [];
    foreach ($i as $k => $v) {
        $parts[] = $k . '=' . var_export($v, true);
    }
    echo implode(', ', $parts), "\n";
}

echo "== pathinfo flags ==\n";
foreach ([1, 2, 4, 8, 3, 6, 0] as $f) {
    echo 'flags=', $f, ' => ', var_export(pathinfo('/a/b/c.txt', $f), true), "\n";
}
echo 'noext,EXT => ', var_export(pathinfo('/a/noext', 4), true), "\n";
echo 'PATHINFO_EXTENSION => ', var_export(pathinfo('/a/b/c.txt', PATHINFO_EXTENSION), true), "\n";
echo 'PATHINFO_FILENAME => ', var_export(pathinfo('/a/b/c.txt', PATHINFO_FILENAME), true), "\n";
echo 'PATHINFO_DIRNAME => ', var_export(pathinfo('/a/b/c.txt', PATHINFO_DIRNAME), true), "\n";
echo 'PATHINFO_BASENAME => ', var_export(pathinfo('/a/b/c.txt', PATHINFO_BASENAME), true), "\n";
