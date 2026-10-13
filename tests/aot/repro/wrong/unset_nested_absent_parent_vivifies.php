<?php
// unset($a["zz"][0]) on an absent parent vivifies it instead of leaving $a unchanged
// issue: #91
$a = ["x" => 1];
unset($a["zz"][0]);
echo json_encode($a), "\n";
