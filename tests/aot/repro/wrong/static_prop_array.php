<?php
// Static array properties: compound assign prints garbage, nested literal initialiser reads garbage
// issue: #62
final class S {
    public static array $a = [1, 2];
    public static $n = [[1, 2], [3]];
}
S::$a[0] += 5;
echo json_encode(S::$a), json_encode(S::$n), "\n";
