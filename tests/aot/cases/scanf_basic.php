<?php
// sscanf / fscanf (array-return form)
print_r(sscanf("age 42", "age %d"));
print_r(sscanf("12 foo 3.5", "%d %s %f"));
print_r(sscanf("ff 10 777", "%x %o %d"));
print_r(sscanf("(1,2)", "(%d,%d)"));
print_r(sscanf("key=val", "%[^=]=%s"));
print_r(sscanf("-5 -2.5 1e3", "%d %f %f"));
print_r(sscanf("12345", "%2d%3d"));
var_dump(sscanf("  ", "%d"));
var_dump(sscanf("42 x", "%d %f %s"));
var_dump(sscanf("12x", "%dy"));

$f = fopen("/tmp/_mc_scan.txt", "w");
fwrite($f, "100 3.5 hello\n42 7 world\n");
fclose($f);
$f = fopen("/tmp/_mc_scan.txt", "r");
while (($r = fscanf($f, "%d %f %s")) !== false) {
    print_r($r);
}
fclose($f);
unlink("/tmp/_mc_scan.txt");
