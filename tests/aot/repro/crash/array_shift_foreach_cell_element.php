<?php
// array_shift on a foreach value taken from a decoded nested array crashes.
// issue: #515
$r = json_decode('[[["t"]],[["a"],["q","1"]]]', true);
foreach ($r as $i => $parts) { $part = array_shift($parts); echo $i, ' ', json_encode($part), ' ', json_encode($parts), "\n"; }
