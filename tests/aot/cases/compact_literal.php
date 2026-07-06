<?php
// compact('a', 'b', ...) with literal names builds an assoc array from the
// named locals, preserving each value's type (int/string/float/array).
function make($id) {
    $name = "widget";
    $price = 9.5;
    $tags = ["a", "b"];
    return compact('id', 'name', 'price', 'tags');
}
$r = make(7);
echo $r['id'], "\n";
echo $r['name'], "\n";
echo $r['price'], "\n";
echo $r['tags'][1], "\n";
echo count($r), "\n";

// single name, and use in string interpolation of the result
$city = "Kyiv";
$c = compact('city');
echo $c['city'], "\n";
