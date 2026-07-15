<?php

echo preg_match('/b/', 'abc'), "\n";
echo preg_match('/x/', 'abc'), "\n";

preg_match('/(\d+)-(\d+)/', 'foo 12-345 bar', $m);
echo $m[0], "\n";
echo $m[1], "\n";
echo $m[2], "\n";

echo preg_match('/^hello/i', 'HELLO world'), "\n";
echo preg_match('/^world/', 'HELLO world'), "\n";

preg_match('/a(x)?(b)/', 'ab', $m2);
echo count($m2), "\n";
echo "[", $m2[1], "]\n";
echo "[", $m2[2], "]\n";

echo preg_quote('a.b*c+d'), "\n";
echo preg_quote('a/b.c', '/'), "\n";
