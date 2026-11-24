<?php
// A nested write / probe / unset through ArrayObject or ArrayIterator creates or skips a missing
// element without a warning, as php's C handler does; a bare read and a compound op still raise (#718).
$ao = new ArrayObject([]);
$ao['y']['k'] = 'v';
$ao['z'][] = 1;
$ao['q']['n'] ??= 3;
unset($ao['u']['v']);
echo isset($ao['m']['n']) ? 'set' : 'unset', ' ', $ao['m']['n'] ?? 'dflt', "\n";
echo json_encode($ao->getArrayCopy()), "\n";
$ai = new ArrayIterator([]);
$ai['y']['k'] = 'v';
echo json_encode($ai->getArrayCopy()), "\n";
try { echo $ao['nope']; } catch (\OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
try { $ao['c']['n'] += 1; } catch (\OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
