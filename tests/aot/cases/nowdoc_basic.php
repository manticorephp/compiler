<?php
$name = "Bob";
$y = <<<'NOW'
Raw $name no interp
literal {$x} and \n stays
NOW;
echo $y, "\n";
$e = <<<E
E;
var_dump($e);
