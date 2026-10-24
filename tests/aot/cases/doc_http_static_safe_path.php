<?php
// Http\safePath() turns a request path into a file path that cannot leave the root.

// A throw-away document root, with a secret file next to it.
$base = sys_get_temp_dir() . '/mc_doc_safe_path_' . getmypid();
$root = $base . '/public';
mkdir($root . '/css', 0777, true);
file_put_contents($root . '/index.html', '<h1>home</h1>');
file_put_contents($root . '/css/app.css', 'body{}');
file_put_contents($base . '/secret.txt', 'outside the root');
$real = realpath($root);

foreach (['/index.html', '/css/app.css', '/css/', '/css', '/missing.txt', '/../secret.txt', 'no-slash'] as $path) {
    $file = Http\safePath($root, $path);
    echo str_pad($path, 16), $file === null ? 'null' : substr($file, strlen($real)), "\n";
}

echo Http\mimeFor('/css/app.css'), "\n";
echo Http\mimeFor('photo.JPG'), "\n";
echo Http\mimeFor('archive.unknown'), "\n";

unlink($root . '/index.html');
unlink($root . '/css/app.css');
rmdir($root . '/css');
rmdir($root);
unlink($base . '/secret.txt');
rmdir($base);
