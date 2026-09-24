<?php

// realpath() of a RELATIVE path resolves against the working directory, which
// the runtime caches (php's virtual cwd) and chdir() moves. '' is the cwd.

$base = \sys_get_temp_dir() . '/mc_realpath_' . \getmypid();
@\mkdir($base . '/a/b', 0777, true);
\touch($base . '/a/b/f.txt');
$real = \realpath($base);
\chdir($base);
echo \realpath('a/b/f.txt') === $real . '/a/b/f.txt' ? 'rel ok' : 'rel BAD', "\n";
echo \realpath('') === \getcwd() ? 'empty ok' : 'empty BAD', "\n";
echo \realpath('./a/../a/b') === $real . '/a/b' ? 'dots ok' : 'dots BAD', "\n";
\chdir('a');
echo \realpath('b/f.txt') === $real . '/a/b/f.txt' ? 'after chdir ok' : 'after chdir BAD', "\n";
echo \var_export(\realpath('nope/x'), true), "\n";
\unlink($base . '/a/b/f.txt');
\rmdir($base . '/a/b');
\rmdir($base . '/a');
\rmdir($base);
