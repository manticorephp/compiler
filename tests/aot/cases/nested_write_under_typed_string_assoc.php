<?php
// A 3-level nested write under an array whose elements are all strings throws "Cannot access offset of type string on string".
$p = ["k.dir" => "x"];
$p["m"]["F"]["n"] = "v";
print_r($p);
