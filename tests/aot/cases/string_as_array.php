<?php
$s = "hello";
echo $s[0], $s[1], $s[4], "\n";     // positive read
echo $s[-1], $s[-2], $s[-5], "\n";  // negative read
$s[0] = "H";                         // in-place write
echo $s, "\n";
$s[5] = "!";                         // write one past end
echo $s, "\n";
$s[10] = "X";                        // write far past end (space pad)
echo $s, "|", strlen($s), "\n";
$w = "abc";
$w[-1] = "Z";                        // negative write
echo $w, "\n";
var_dump(isset($s[2]));
var_dump(isset($s[100]));
var_dump(isset($s[-1]));
var_dump(isset($s[-100]));
$empty = "";
var_dump(isset($empty[0]));
echo "[", $s[100], "]\n";            // out-of-range read → ""
