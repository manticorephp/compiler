<?php
// A function called by string name inside a loop returns 0 instead of its result.
function a() { $f = 'strlen'; $c = -1; for ($i = 0; $i < 2; $i++) { $c = $f('abc'); } return $c; }
echo a(), "\n";
