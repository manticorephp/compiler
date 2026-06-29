<?php
$o = new stdClass();
$o->x = 10;
$o->y = "hi";
$o->z = 2.5;
echo $o->x, "/", $o->y, "/", $o->z, "\n";
$a = (object)["name" => "Ada", "age" => 36];
echo $a->name, "/", $a->age, "\n";
$back = (array)$a;
echo $back["name"], "\n";
