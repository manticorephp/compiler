<?php
// json_encode() of an ArrayObject answers {} instead of its storage's elements.
// issue: #589
echo json_encode(new ArrayObject([1])), json_encode(new ArrayObject(['a' => 2])), "\n";
