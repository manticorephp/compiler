<?php
// var_dump / var_export floats: shortest round-trip (serialize_precision=-1),
// now via the shared Ryu core. var_dump and var_export use an uppercase `E` and
// the same decimal/scientific threshold as json (so 1e15/1e16 stay decimal, not
// the "1e+15" the old snprintf-probe produced); var_export additionally forces a
// trailing `.0` on an integer-valued decimal so it re-parses as a float.
$vals = [1.0e20, 1.0e-5, 1.0e15, 1.0e16, 1.0e17, 100.0, 9.5, 1.0 / 3.0,
         2.0 / 3.0, 0.0001, 1.5e-4, -9.5, 0.0, -0.0, 5.0e-324,
         1.7976931348623157e308, 1234567890123.0];
foreach ($vals as $v) {
    var_dump($v);
}
echo "--- var_export ---\n";
echo var_export(1.0e20, true), "\n";
echo var_export(1.0e15, true), "\n";
echo var_export(100.0, true), "\n";
echo var_export(9.5, true), "\n";
echo var_export(1.0 / 3.0, true), "\n";
echo var_export(-0.0, true), "\n";
echo var_export(1234567890123.0, true), "\n";
