<?php

// var_export of anything non-scalar used to print the literal '?'.

echo "-- flat list --\n";
var_export([1, 2]);
echo "\n";

echo "-- assoc --\n";
var_export(['a' => 1, 'b' => 'x']);
echo "\n";

echo "-- nested --\n";
var_export(['a' => [1, 2], 'b' => ['c' => true]]);
echo "\n";

echo "-- deep nest --\n";
var_export(['x' => ['y' => ['z' => 1]]]);
echo "\n";

echo "-- empty --\n";
var_export([]);
echo "\n";

echo "-- mixed value kinds --\n";
var_export([1, 1.5, 's', true, false, null]);
echo "\n";

echo "-- float keeps .0 --\n";
var_export([100.0, 0.1, -2.5]);
echo "\n";

echo "-- quoting --\n";
var_export(["a'b", 'c\d', "both'\\here"]);
echo "\n";

echo "-- sparse + mixed keys --\n";
var_export([3 => 'a', 'k' => 'b', 4 => 'c']);
echo "\n";

echo "-- return mode --\n";
$s = var_export([1, ['x' => 2]], true);
echo $s, "\n";
var_dump(strlen($s) > 0);

echo "-- scalars still inline --\n";
var_export(1);
echo "\n";
var_export('s');
echo "\n";
var_export(null);
echo "\n";
var_export(true);
echo "\n";
var_export(1.0);
echo "\n";

echo "-- from a cell-returning function --\n";
function mk(int $n): array|string
{
    if ($n === 0) {
        return 'str';
    }
    return ['a' => 'xx', 'b' => 'yy'];
}
var_export(mk(1));
echo "\n";
var_export(mk(0));
echo "\n";

echo "-- element of a returned array --\n";
foreach (mk(1) as $k => $v) {
    echo $k, '=';
    var_export($v);
    echo "\n";
}

echo "-- pathinfo round-trip --\n";
var_export(pathinfo('/www/htdocs/inc/lib.inc.php'));
echo "\n";

echo "-- int-keyed nested list --\n";
var_export([[1, 2], [3, 4]]);
echo "\n";
