<?php
// explode() is binary-safe: a NUL delimiter and a NUL-bearing subject split by length, not as C strings
// (symfony's polyfill-deepclone writes explode("\0", $class, 2)).
function head(string $class): string
{
    return 'Class "' . explode("\0", $class, 2)[0] . '" not found.';
}
echo head("Foo\0bar"), "\n";
echo head('plain'), "\n";
echo json_encode(explode("\0", "a\0b\0c")), "\n";
echo json_encode(explode("\0", "a\0b\0c", 2)), "\n";
echo json_encode(explode("\0", "a\0b\0c", -1)), "\n";
echo json_encode(explode("\0", "")), "\n";
echo json_encode(explode("b\0", "ab\0cb\0d")), "\n";
echo json_encode(explode(",", "x,y,,z")), "\n";
echo json_encode(explode("\0", "\0\0")), "\n";
