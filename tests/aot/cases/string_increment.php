<?php
// Perl-style alphanumeric increment
$a = "az"; $a++; echo $a, "\n";        // ba
$b = "Zz"; $b++; echo $b, "\n";        // AAa
$c = "a9"; $c++; echo $c, "\n";        // b0
$d = "Az"; $d++; echo $d, "\n";        // Ba
$e = ""; $e++; echo $e, "\n";          // 1
$f = "Zz9"; $f++; echo $f, "\n";       // AAa0

// numeric strings increment numerically (int / float)
$g = "5"; var_dump($g++); var_dump($g);   // "5" then int(6)
$h = "9"; $h++; var_dump($h);             // int(10)
$k = "5.5"; $k++; var_dump($k);           // float(6.5)

// pre-increment returns the new value
$p = "Ay"; var_dump(++$p);                // "Az"

// chained numeric promotion
$n = "8"; $n++; $n++; $n++; var_dump($n); // int(11)

// spreadsheet column names
$col = "A";
$out = [];
for ($i = 0; $i < 5; $i++) { $out[] = $col; $col++; }
echo implode(",", $out), "\n";            // A,B,C,D,E

// used in string context after increment
$w = "aa"; $w++; echo "next: $w\n";       // next: ab
