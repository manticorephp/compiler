<?php
class S {
    public static $n = 0;
    public static $arr = [];
    public static mixed $m = 0;
    public static $s;
}
S::$n = S::$n + 1;
var_dump(S::$n);
S::$arr[] = 'a';
S::$arr['k'] = 2;
var_dump(S::$arr);
var_dump(S::$m);
S::$m = 2.5;
var_dump(S::$m);
S::$m = [1];
var_dump(S::$m);
var_dump(S::$s);
S::$s = "str";
var_dump(S::$s);
S::$s = 65536;
var_dump(S::$s + 1);
