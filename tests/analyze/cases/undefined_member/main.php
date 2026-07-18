<?php

$b = new Box();
$b->open();               // ok
$b->close();              // unknown method Box->close()
$b->width("x");           // arg.type via typed local receiver
echo Box::SIZE, "\n";     // ok
echo Box::WEIGHT, "\n";   // unknown constant Box::WEIGHT
$b::make();               // ok (dynamic static dispatch)
$b::ghost();              // unknown method Box::ghost()
