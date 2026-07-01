<?php
echo implode("|", explode(",", "a,b,c,d")), "\n";
echo count(explode(",", "single")), "\n";
echo "[", implode(",", explode(",", "")), "]\n";
echo implode("|", explode(",", "a,,c,")), "\n";
echo implode("|", explode("::", "x::y::z")), "\n";
echo implode("|", explode(",", "a,b,c,d,e", 3)), "\n";
$p = explode("-", "2026-06-30");
echo $p[0] + $p[1] + $p[2], "\n";
