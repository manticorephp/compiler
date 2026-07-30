<?php

// A variadic collects into an array, and a HOMOGENEOUS one packs RAW while
// __mc_format reads cells — so a single-int fprintf used to print 0. Two args
// of different types masked it, because a heterogeneous literal boxes.
$f = fopen('php://output', 'w');

fprintf($f, "one(%d)\n", 5);
fprintf($f, "two(%d,%s)\n", 5, "a");
fprintf($f, "three(%d,%d,%d)\n", 1, 2, 3);
fprintf($f, "str(%s)\n", "only");
fprintf($f, "float(%.2f)\n", 1.5);
fprintf($f, "none\n");
$n = fprintf($f, "count(%d)\n", 42);
echo "returned=", $n, "\n";

// The array-taking siblings, for contrast — these always worked.
vfprintf($f, "vf(%d)\n", [7]);
$args = [9];
vprintf("vp(%d)\n", $args);
echo sprintf("sp(%d)\n", 11);
printf("pf(%d)\n", 13);
