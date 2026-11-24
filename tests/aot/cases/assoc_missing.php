<?php
// A missing key on a typed (int-valued) array: php warns `Undefined array key "nope"` and reads
// null; where Zend warns, Manticore throws (#718). `??` / isset stay quiet.
$cfg = ["host" => 5];
try {
    echo $cfg["host"], ",", $cfg["nope"];
} catch (\OutOfBoundsException $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
echo $cfg["nope"] ?? "dflt", ",", isset($cfg["nope"]) ? "set" : "unset", "\n";
$list = [10, 20];
try { echo $list[2]; } catch (\OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
$k = "7";
try { echo [7 => 1][$k], ([7 => 1])["x"]; } catch (\OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
