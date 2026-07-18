<?php

realFn(1);                    // ok (user)
echo strlen("hi"), "\n";      // ok (codegen builtin)
echo str_replace("a", "b", "c"), "\n";  // ok (stdlib)
ghostFn();                    // unknown function
echo trim("  x  "), "\n";     // ok (stdlib)
notAThing(1, 2);              // unknown function
