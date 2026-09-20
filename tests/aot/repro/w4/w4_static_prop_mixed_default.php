<?php
class T { public static mixed $n = 0; public static mixed $f = 1.5; public static mixed $a = [1, 2]; public static mixed $s = "s"; public static mixed $z = null; public static mixed $b = true; }
var_dump(T::$n, T::$f, T::$a, T::$s, T::$z, T::$b);
T::$n += 70000;
var_dump(T::$n);
