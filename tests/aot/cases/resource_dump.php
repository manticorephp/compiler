<?php

// MANTICORE-ONLY — difftest PHP-SKIPs this, and the expected file is written BY
// HAND. Two independent reasons it cannot be a parity case:
//
//  1. `\Resource` as a TYPE HINT fatals under php: there is no such class, and a
//     real resource is not an object, so php raises
//     "must be of type Resource, resource given".
//  2. The ids differ ON PURPOSE. Ours are a counter from 1; php burns 1-3 on the
//     std streams and 4 internally, so its first handle is 5. An id is a wrapper
//     detail, not semantics, so we do not chase it — which is exactly why the
//     var_dump/print_r arms cannot be pinned in resource_basics.php.
//
// ⚠ THE TYPED CALL MUST COME BEFORE ANY OUTPUT, and that is not cosmetic.
// difftest skips a file only when `(rc != 0 AND stdout is EMPTY)` or when stderr
// matches Fatal/Uncaught — and it runs php with `-d error_reporting=0
// -d display_errors=0`, which SILENCES the fatal text, leaving stderr empty. So
// the ONLY thing that earns the skip is php dying having printed nothing. Put a
// var_dump above the typed call and this file silently becomes a DIFF instead of
// a skip. (Measured: it did exactly that.)
//
// What IS pinned here: the FORMAT, verified against php 8.5.8 — only the number
// differs. php prints `resource(5) of type (stream)`, `Resource id #5`, and
// `resource(5) of type (Unknown)` once closed.

$path = sys_get_temp_dir() . '/mc_resource_dump.txt';
@unlink($path);

$fh = fopen($path, 'w');

// FIRST, before anything prints: a statically-typed \Resource — a raw obj
// pointer, not a tagged cell, so a different emitter path than the fopen() union.
// This is also the line php dies on, and it must die with stdout still empty (see
// the header) for difftest to skip the file rather than diff it.
function res_type(\Resource $r): string { return gettype($r); }
function res_debug(\Resource $r): string { return get_debug_type($r); }
var_dump(res_type($fh));
var_dump(res_debug($fh));

// The arm must sit BEFORE is_object in the prelude dumper: a \Resource IS an
// object to us, so the object arm would otherwise print its properties —
// leaking the raw backing address into the output.
var_dump($fh);
print_r($fh);
echo "\n";

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
