<?php
// parse_ini_string across the 3 scanner modes, sections, key[] arrays, comments
$ini = "; a comment\n"
     . "port = 8080\n"
     . "host = \"local host\"\n"
     . "debug = true\n"
     . "quiet = off\n"
     . "[db]\n"
     . "name = app\n"
     . "flag = no\n"
     . "arr[] = x\n"
     . "arr[] = y\n";

echo "--- NORMAL, no sections ---\n";
print_r(parse_ini_string($ini, false, INI_SCANNER_NORMAL));
echo "--- NORMAL, sections ---\n";
print_r(parse_ini_string($ini, true, INI_SCANNER_NORMAL));
echo "--- TYPED, sections ---\n";
print_r(parse_ini_string("n=42\nf=1.5\nb=true\nx=off\ns=hello", false, INI_SCANNER_TYPED));
echo "--- RAW ---\n";
print_r(parse_ini_string("a=true\nb=\"x y\"\nc=d ; trailing", false, INI_SCANNER_RAW));
