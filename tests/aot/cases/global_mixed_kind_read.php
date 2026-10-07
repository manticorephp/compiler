<?php
// A global the scopes store with different kinds (null at the top, an array in
// a function) is one slot: a scope that only READS it sees what the last store
// left, and the store releases what it overwrites.
$cfg = null;
function setg(int $i): void { global $cfg; $cfg = ['name' => 'n' . $i, 'pad' => str_repeat('p', 32)]; }
function getg(): string { global $cfg; return $cfg === null ? 'null' : $cfg['name']; }
function clearg(): void { global $cfg; $cfg = null; }
echo getg(), "\n";
setg(5);
echo getg(), "\n";
var_dump($cfg);
clearg();
echo getg(), "\n";
var_dump($cfg === null);
