<?php

// The scheme resolver: every opener funnels through it, so a new wrapper is one
// arm rather than a new call path. This is the seam Ф4 plugs https:// into —
// which is why an `if (strpos($p,'http://')===0)` shortcut was not acceptable:
// https is the same protocol over a different transport, and the shortcut would
// be torn out at once.
//
// Every rule below was MEASURED against php 8.5.8 rather than assumed — notably
// that the scheme is case-insensitive, that a host is allowed and ignored, and
// that a scheme which cannot be resolved is NOT quietly treated as a filename.

$dir = sys_get_temp_dir();
$path = $dir . '/mc_scheme_case.txt';
file_put_contents($path, "CONTENT\n");

// No scheme at all — the ordinary case, and it must not have changed.
var_dump(file_get_contents($path));

// file:// in its three accepted spellings.
var_dump(file_get_contents('file://' . $path));
var_dump(file_get_contents('FILE://' . $path));          // scheme is case-insensitive
var_dump(file_get_contents('file://localhost' . $path)); // host allowed, ignored

// A scheme must start with a letter. "2bad" does not, so php reads the whole
// string as a filename — which does not exist.
var_dump(@file_get_contents('2bad://' . $path));

// A well-formed but unregistered scheme is NOT a filename: php finds no wrapper
// and fails. Silently opening it as a relative path would be worse than failing.
var_dump(@file_get_contents('nosuch://' . $path));

// fopen() goes through the same resolver.
$fh = fopen('file://' . $path, 'r');
var_dump($fh !== false);
var_dump(fgets($fh));
fclose($fh);

$fh = @fopen('nosuch://' . $path, 'r');
var_dump($fh);

// A path that merely CONTAINS "://" is still a path: the part before it is not a
// valid scheme, so it is a filename.
$weird = $dir . '/mc_a://b';
var_dump(@file_get_contents($weird));

unlink($path);
echo "done\n";
