<?php
// min/max over non-numeric operands: PHP orders strings and arrays too, and the
// result keeps that TYPE. These used to unbox an array pointer as an int and
// print a raw address.
var_dump(max([1,2],[1,3]));
var_dump(min([1,2],[1,3]));
var_dump(max([1,2,3],[1,2]));
var_dump(max("a","b"), min("a","b"));
var_dump(max("apple","banana","cherry"), min("apple","banana","cherry"));

// The one-array form is "the max of its ELEMENTS", not the array.
var_dump(max([1,2,3]), min([3,1,2]));
var_dump(max(["a","c","b"]), min(["a","c","b"]));
var_dump(max([1,2.5,2]), min([3,1.5,2]));

// Numeric forms are unchanged.
var_dump(max(1,2), min(1,2), max(1,2.5), min(3,1.5), max(1,2,3), min(3,2,1));

// array_keys with a search value — loose by default, strict on request.
var_dump(array_keys([1,"1",2], 1));
var_dump(array_keys([1,"1",2], 1, true));
var_dump(array_keys(["a"=>1,"b"=>2,"c"=>1], 1));
var_dump(array_keys([1,2,3], 9));
var_dump(array_keys(["x"=>"v","y"=>"v"], "v"));
var_dump(array_keys([[1,2],[3]], [3]));
var_dump(array_keys([1,2,3]));
