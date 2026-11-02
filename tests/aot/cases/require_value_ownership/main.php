<?php
// The value of `require` of a unit that returns an array: one require twice at global scope (a double release under a strict allocator), and a per-call function-local require (realpath's malloc'd buffer leaked per call).
// @serial: a memory measurement.
function local(): int { $x = require __DIR__ . '/conf.php'; return count($x); }
function arg(): int { return count(require __DIR__ . '/conf.php'); }
function bare(): void { require __DIR__ . '/conf.php'; }
function elem(): int { $o = ['k' => require __DIR__ . '/conf.php']; return count($o['k']); }
$x1 = require __DIR__ . '/conf.php';
$x2 = require __DIR__ . '/conf.php';
var_dump(strlen($x1['n']), $x2['m'][2], (require __DIR__ . '/conf.php')['m'][0]);
var_dump(local(), arg(), elem());
bare();
foreach (['local', 'arg', 'bare', 'elem'] as $f) {
    $f();
    $b = memory_get_usage();
    for ($i = 0; $i < 100000; $i++) { $f(); }
    echo $f, ' ', (memory_get_usage() - $b) < 2000000 ? 'flat' : 'grew', "\n";
}
