<?php
function len(?string $s): int { return strlen($s); }
function sub(?string $s): string { return substr($s, 0, 3); }
function up(?string $s): string { return strtoupper($s); }
echo len(null), "\n";
echo len("hello"), "\n";
echo "[", sub(null), "]\n";
echo "[", sub("hello"), "]\n";
echo "[", up(null), "]\n";
echo "[", up("abc"), "]\n";
