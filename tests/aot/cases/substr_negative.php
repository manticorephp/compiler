<?php
$s = "Hello, World!";
echo substr($s, -6), "\n";
echo substr($s, -6, 5), "\n";
echo substr($s, 0, -1), "\n";
echo substr($s, -3, -1), "\n";
echo substr($s, 7, 100), "\n";
echo substr($s, -100, 3), "\n";
echo "[", substr($s, 5, -10), "]\n";
