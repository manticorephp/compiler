<?php
// A store THROUGH a reference alias of a global is a store to the global: its
// kind joins the global's type, so every scope reads the slot the same way.
$g = ['k' => 'v'];
function viaAlias(int $i): void { global $g; $a = &$g; $b = &$a; $b = 'str' . $i; }
function back(): void { global $g; $g = ['k' => 'w']; }
function show(): string { global $g; return is_array($g) ? 'array:' . $g['k'] : 'string:' . $g; }
echo show(), "\n";
viaAlias(1);
echo show(), "\n";
back();
echo show(), "\n";
viaAlias(2);
echo show(), "\n";
