<?php

// Every render path interleaved. Before the output funnel a string echo did an
// unbuffered write(1, …) while the others went through stdio printf, so the
// only thing keeping this in order was an fflush(NULL) per string echo.
$s = "str";
$i = -42;
$f = 1.5;
$b = true;
$big = 1.0E+20;

echo $s;
echo $i;
echo $f;
echo $b;
echo "\n";

printf("[%s %d %.2f]", $s, $i, $f);
echo "|after-printf\n";

echo $big, "\n";
echo 0.1 + 0.2, "\n";

var_dump($f);
echo "|after-var_dump\n";

print_r([1, "two", 3.5]);
echo "|after-print_r\n";

var_export(["k" => 1, "v" => "x"]);
echo "|after-var_export\n";

echo sprintf("%e", 12345.678), "\n";
printf("%e\n", 12345.678);

$parts = ["a", "b", "c"];
foreach ($parts as $k => $p) {
    echo $k, ":", $p, " ";
}
echo "\n";
