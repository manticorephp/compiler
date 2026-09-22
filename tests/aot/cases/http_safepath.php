<?php

// MANTICORE-ONLY: Http\safePath() / Http\mimeFor() are superset API.
$pid = (string)getmypid();
$root = rtrim(sys_get_temp_dir(), '/') . '/mc_safepath_' . $pid;
$out = $root . '/outside_' . $pid;
mkdir($root . '/pub/css', 0777, true);
mkdir($out, 0777, true);
file_put_contents($root . '/pub/index.html', 'i');
file_put_contents($root . '/pub/css/app.css', 'c');
file_put_contents($out . '/secret.txt', 's');
symlink($out . '/secret.txt', $root . '/pub/link.txt');
// realpath: on macOS /var is a symlink to /private/var, so the answer's prefix
// is the RESOLVED root, not the one that was passed in.
$pub = realpath($root . '/pub');

$cases = ['/index.html', '/css/app.css', '/css/../index.html', '/../outside_' . $pid . '/secret.txt',
    '/%2e%2e/index.html', '/link.txt', '/css', '/css/', '/', '/missing.html', '', '/index.html/'];
foreach ($cases as $c) {
    $p = \Http\safePath($pub, $c);
    $label = $c === '' ? '(empty)' : str_replace($pid, 'PID', $c);
    echo str_pad($label, 40), ' => ', $p === null ? 'null' : substr($p, strlen($pub)), "\n";
}
foreach (['a.html', 'x/y.css', 'a.js', 'a.mjs', 'a.json', 'a.png', 'a.svg', 'a.woff2', 'a.wasm', 'a.txt', 'a.unknown', 'noext', 'a.JPG'] as $f) {
    echo str_pad($f, 10), ' ', \Http\mimeFor($f), "\n";
}

unlink($root . '/pub/link.txt');
unlink($out . '/secret.txt');
unlink($root . '/pub/css/app.css');
unlink($root . '/pub/index.html');
rmdir($root . '/pub/css');
rmdir($root . '/pub');
rmdir($out);
rmdir($root);
