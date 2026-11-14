<?php
function b(): int { return 2; }
function a(): int { return b() + 1; }
function c(): int { return 3; }
echo a(), "\n";
