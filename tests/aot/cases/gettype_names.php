<?php
class Foo {}
echo gettype(1), "\n";
echo gettype(1.5), "\n";
echo gettype("s"), "\n";
echo gettype(true), "\n";
echo gettype(null), "\n";
echo gettype([1,2]), "\n";
echo gettype(new Foo()), "\n";
echo get_debug_type(1.5), "\n";
echo get_debug_type([1]), "\n";
echo get_debug_type(new Foo()), "\n";
function d(mixed $x): string { return gettype($x); }
echo d(2.5), "\n";
echo d([1]), "\n";
