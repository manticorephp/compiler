<?php

// \Resource — the stdlib's file/dir handle. php has no writable `resource`
// type, so this is a real class; these are the observable consequences.
//
// NO RAW IDS ANYWHERE: ours are a plain counter from 1, php's start at 5 (it
// burns 1-3 on the std streams and 4 internally). That divergence is deliberate
// — an id is a wrapper detail — so a case that printed one could never match.

$path = sys_get_temp_dir() . '/mc_resource_basics.txt';
@unlink($path);

$fh = fopen($path, 'w');
var_dump($fh !== false);
var_dump(is_resource($fh));
var_dump(get_resource_type($fh));

// gettype/get_debug_type through a CELL: fopen(): \Resource|false is a union,
// so the tag-select chain sees only "object" unless the class is checked.
var_dump(gettype($fh));
var_dump(get_debug_type($fh));
// php: a resource is NOT an object. We say it is — a known, deliberate
// divergence, pinned here so it can't drift silently.
// var_dump(is_object($fh));  // php: false, us: true

fwrite($fh, "line one\n");
fwrite($fh, "line two\n");
var_dump(fclose($fh));

// Closed: still an object, but type flips and it stops being a resource.
var_dump(is_resource($fh));
var_dump(get_resource_type($fh));
var_dump(gettype($fh));
var_dump(get_debug_type($fh));

// Re-open and walk the handle API.
$fh = fopen($path, 'r');
echo fgets($fh);
var_dump(ftell($fh));
var_dump(rewind($fh));
var_dump(fread($fh, 4));
var_dump(feof($fh));
$st = fstat($fh);
var_dump($st['size']);
fclose($fh);

// After a `!== false` guard the union may narrow to a plain \Resource, which is
// a different emitter path (a raw obj pointer, not a tagged cell). Note a
// `\Resource` TYPE HINT is manticore-only — php has no such class and fatals
// with "must be of type Resource, resource given" — so it cannot be tested here.
$fh = fopen($path, 'r');
if ($fh !== false) {
    var_dump(gettype($fh));
    var_dump(get_debug_type($fh));
    fclose($fh);
}

// var_export of a resource is NULL in php.
$fh = fopen($path, 'r');
var_export($fh); echo "\n";
fclose($fh);

// A directory handle reports "stream" too, not "dir".
$dh = opendir(sys_get_temp_dir());
var_dump(is_resource($dh));
var_dump(get_resource_type($dh));
var_dump(gettype($dh));
closedir($dh);
var_dump(is_resource($dh));

// tmpfile(): must be a real \Resource, not a raw handle — it feeds the same
// f* family as fopen.
$tf = tmpfile();
var_dump(is_resource($tf));
var_dump(get_resource_type($tf));
fwrite($tf, "temp\n");
rewind($tf);
echo fgets($tf);
fclose($tf);

@unlink($path);
echo "done\n";
