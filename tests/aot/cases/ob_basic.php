<?php

// Every getter answers false / 0 with no buffer open.
var_dump(ob_get_contents());
var_dump(ob_get_length());
var_dump(ob_get_level());
var_dump(ob_clean());
var_dump(ob_end_clean());
var_dump(ob_end_flush());
var_dump(ob_flush());

ob_start();
echo "captured";
echo 42;
echo 1.5;
echo true;
$s = ob_get_clean();
echo "got[", $s, "] len=", strlen($s), " level=", ob_get_level(), "\n";

// ob_get_contents is a PEEK — the buffer keeps accumulating after it.
ob_start();
echo "one";
$a = ob_get_contents();
echo "two";
$b = ob_get_contents();
echo "len=", ob_get_length();
ob_end_clean();
echo "a=[", $a, "] b=[", $b, "]\n";

// ob_clean empties without closing.
ob_start();
echo "dropped";
var_dump(ob_clean());
echo "kept";
echo "|", ob_get_length();
$c = ob_get_clean();
echo "c=[", $c, "]\n";

// ob_end_flush writes downstream.
ob_start();
echo "flushed-out";
var_dump(ob_end_flush());
echo "|\n";
echo "final level=", ob_get_level(), "\n";
