<?php
// Calling a string that names a codegen builtin through `$cb(...$args)` crashes the compiler: Call to a member function accept() on null
// issue: #411
$cb = 'str_repeat';
$args = ['ab', 3];
echo $cb(...$args), "\n";
