<?php
// str_getcsv — all 4 args explicit (php 8.5 warns on default $escape → poisons expected)
print_r(str_getcsv("a,b,c", ",", "\"", ""));
print_r(str_getcsv("\"a,b\",\"c\"\"d\"", ",", "\"", ""));
print_r(str_getcsv("x,,z", ",", "\"", ""));
print_r(str_getcsv("a,b,", ",", "\"", ""));
print_r(str_getcsv("  a , b ", ",", "\"", ""));
print_r(str_getcsv("one;two;three", ";", "\"", ""));
print_r(str_getcsv("'q,x',y", ",", "'", ""));
var_dump(str_getcsv("", ",", "\"", ""));
