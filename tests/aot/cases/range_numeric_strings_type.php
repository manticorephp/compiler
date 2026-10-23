<?php
// range("1", "4") returns ints; php 8.5 keeps single-char numeric-string endpoints as strings.
var_dump(range("1", "3"));
echo json_encode(range("1", 3)), json_encode(range("01", "03")), json_encode(range("1", "3", 2)), json_encode(range("a", "c")), "\n";
