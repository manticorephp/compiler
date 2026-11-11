<?php
function target(): int { return 1; }
function direct(): int { return 2; }
$f = implode('', ['tar', 'get']);
echo $f(), direct(), "\n";
