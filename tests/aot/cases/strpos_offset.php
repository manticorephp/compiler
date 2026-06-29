<?php
var_dump(strpos("hello", "l"));
var_dump(strpos("hello", "x"));
var_dump(strpos("hello", "l", 3));
var_dump(strpos("hello world", "o", 5));
var_dump(strpos("aaa", "a", 1));
echo (strpos("hi", "z") === false) ? "miss\n" : "BAD\n";
