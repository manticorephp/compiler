<?php

// print in expression position — the reason it cannot just be a statement.
$c = true;
$c and print "and-fired\n";

$d = false;
$d and print "never\n";

$e = false;
$e or print "or-fired\n";

// Lowest precedence, right-associative: `print $x = 5` prints the ASSIGNED
// value, because the operand parse swallows the assignment.
$x = 0;
print $x = 5;
print "\n";
echo $x, "\n";

// Concatenation binds tighter than print.
print "a" . "b" . "\n";

$sum = (print "p\n") + (print "q\n");
echo $sum, "\n";
