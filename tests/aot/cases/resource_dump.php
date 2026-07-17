<?php

// MANTICORE-ONLY — difftest PHP-SKIPs this, and the expected file is written BY
// HAND. Two independent reasons it cannot be a parity case:
//
//  1. `\Resource` as a TYPE HINT fatals under php: there is no such class, and a
//     real resource is not an object, so php raises
//     "must be of type Resource, resource given". That fatal is what makes
//     difftest skip the file (it skips on a php fatal, not on a marker).
//  2. The ids differ ON PURPOSE. Ours are a counter from 1; php burns 1-3 on the
//     std streams and 4 internally, so its first handle is 5. An id is a wrapper
//     detail, not semantics, so we do not chase it — which is exactly why the
//     var_dump/print_r arms cannot be pinned in resource_basics.php.
//
// What IS pinned here: the FORMAT, verified against php 8.5.8 — only the number
// differs. php prints `resource(5) of type (stream)`, `Resource id #5`, and
// `resource(5) of type (Unknown)` once closed.

$path = sys_get_temp_dir() . '/mc_resource_dump.txt';
@unlink($path);

$fh = fopen($path, 'w');

// The arm must sit BEFORE is_object in the prelude dumper: a \Resource IS an
// object to us, so the object arm would otherwise print its properties —
// leaking the raw backing address into the output.
var_dump($fh);
print_r($fh);
echo "\n";

// A statically-typed \Resource — a raw obj pointer, not a tagged cell, so a
// different emitter path than the fopen() union above.
function res_type(\Resource $r): string { return gettype($r); }
function res_debug(\Resource $r): string { return get_debug_type($r); }
var_dump(res_type($fh));
var_dump(res_debug($fh));

// Closed: keeps its id, type flips to "Unknown".
fclose($fh);
var_dump($fh);

// A DIR handle reports "stream", like php's.
$dh = opendir(sys_get_temp_dir());
var_dump($dh);
closedir($dh);
var_dump($dh);

// STDOUT is a \Resource too, cached per stream so identity holds. Its id is 3
// here only because two handles were opened first — php pins it at 2. Marked
// persistent: it is libc's global, so dropping it must NOT fclose real stdout.
var_dump(STDOUT === STDOUT);
var_dump(STDERR === STDERR);
var_dump(STDOUT === STDERR);
var_dump(get_resource_type(STDOUT));
var_dump(is_resource(STDOUT));

@unlink($path);
echo "done\n";
