<?php
namespace N;
const X = 5;
define('Y', 7);
class U {
    public static array $a = [X, Y, \PHP_INT_SIZE, CURLE_OK];
    public static int $b = X + 1;
}
echo implode(',', U::$a), '|', U::$b, "\n";
