<?php

ob_start();
echo "L1-";
echo "level=", ob_get_level(), " ";
ob_start();
echo "L2-";
echo "level=", ob_get_level(), " ";
ob_start();
echo "L3";
echo "level=", ob_get_level();
$three = ob_get_clean();
echo "<", $three, ">";
$two = ob_get_clean();
echo "<", $two, ">";
$one = ob_get_clean();
echo "one=[", $one, "]\n";
echo "back to level=", ob_get_level(), "\n";

// An inner end_flush lands in the OUTER buffer, not on stdout.
ob_start();
echo "outer(";
ob_start();
echo "inner";
ob_end_flush();
echo ")";
$o = ob_get_clean();
echo "o=[", $o, "]\n";

// Lengths are per level.
ob_start();
echo "aaaa";
ob_start();
echo "bb";
echo "|inner=", ob_get_length();
$i = ob_get_clean();
echo "|outer=", ob_get_length();
$x = ob_get_clean();
echo "i=[", $i, "] x=[", $x, "]\n";
