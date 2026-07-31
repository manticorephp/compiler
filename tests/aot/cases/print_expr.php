<?php

// `print` is an expression that prints its operand and yields int 1.
print "hello\n";
print("parens\n");

$r = print "value\n";
echo $r, "\n";

$n = 7;
print $n;
print "\n";

print 1.5;
print "\n";

print true;
print "\n";

$a = ["x", "y"];
foreach ($a as $v) {
    print $v;
}
print "\n";
