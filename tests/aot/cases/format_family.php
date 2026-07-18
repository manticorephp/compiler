<?php
// vsprintf / vprintf / fprintf / vfprintf + runtime sprintf / printf / spread / positional
echo vsprintf("%d|%5d|%-5d|%05d|%+d\n", [42, 42, 42, 42, 42]);
echo vsprintf("%x %X %o %c\n", [255, 255, 255, 65]);
echo vsprintf("%s|%10s|%-10s|%.3s\n", ["hi", "hi", "hi", "hello"]);
echo vsprintf("%.2f %8.2f %08.2f %+.2f\n", [3.14159, 3.14159, 3.14159, 3.14159]);
echo vsprintf("%e %.3e %g\n", [12345.678, 12345.678, 0.0001]);
echo vsprintf("%'*10d %2\$s-%1\$d\n", [42, "k"]);
vprintf("vprintf %d %s\n", [7, "z"]);

$fmt = "%d/%s/%.1f";
echo sprintf($fmt, 10, "run", 2.5), "\n";            // runtime format
printf("runtime printf %05d %x\n", 7, 255);          // runtime printf
$a = [1, "two", 3.0];
echo sprintf("%d-%s-%.1f", ...$a), "\n";             // spread
echo sprintf("%2\$s=%1\$d\n", 9, "key");             // positional runtime

$f = fopen("/tmp/_mc_fmt.txt", "w");
fprintf($f, "fprintf %d %s %.2f\n", 3, "a", 1.5);
vfprintf($f, "vfprintf %x %b\n", [255, 5]);
fclose($f);
echo file_get_contents("/tmp/_mc_fmt.txt");
unlink("/tmp/_mc_fmt.txt");
