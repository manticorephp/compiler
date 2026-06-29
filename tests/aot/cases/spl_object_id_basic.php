<?php
class X {}
$a = new X();
$b = new X();
echo (spl_object_id($a) === spl_object_id($a)) ? "1" : "0";
echo (spl_object_id($a) === spl_object_id($b)) ? "0" : "1";
