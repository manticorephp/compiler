<?php
class EC { public static function ce(string $s): string { return "ce:" . $s; } }
class H {
    public static $sp = "EC::ce";
    const K = "EC::ce";
    public $inst = "EC::ce";
    public static $arr = ["x" => "EC::ce"];
}
const GK = "EC::ce";
function g(mixed $cb) { return $cb("v"); }
echo g(H::$sp), "\n";
echo g(H::K), "\n";
echo g((new H)->inst), "\n";
echo g(H::$arr["x"]), "\n";
echo g(GK), "\n";
echo g("ec::CE"), "\n";
echo g("\\EC::ce"), "\n";
