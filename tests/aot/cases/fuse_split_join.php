<?php
// implode(explode()) split-join fuses to str_replace (must stay semantically
// exact); non-fusable shapes (limit, reused temp, dynamic delim, multi-char)
// must still compute correctly. Output must match the php interpreter.
$s = "a,b,c,d";
echo implode("|", explode(",", $s)), "\n";        // direct fuse -> a|b|c|d
$p = explode(",", "x,,y");                          // via-temp fuse, empty segment
$j = implode("-", $p);
echo $j, "\n";                                      // x--y
echo implode("|", explode("--", "a--b--c")), "\n";  // multichar delim -> a|b|c
echo implode(",", explode(",", "trailing,")), "\n"; // trailing delim -> trailing,
$q = explode(",", "1,2,3", 2);                      // limit -> NOT fused
echo implode("|", $q), "\n";                         // 1|2,3
$r = explode(",", "m,n,o");                          // reused temp -> NOT fused
echo implode("|", $r), "=", count($r), "\n";         // m|n|o=3
$d = ",";
echo implode("|", explode($d, "p,q")), "\n";         // dynamic delim -> NOT fused -> p|q
