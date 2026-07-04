<?php
// isset() / `??` treat a PRESENT-but-NULL value as unset (PHP semantics).
$a = ["x" => 1, "y" => 2];
$a["x"] = null;
var_dump(isset($a["x"]));      // false — present but null
var_dump(isset($a["y"]));      // true
var_dump(isset($a["z"]));      // false — missing
echo ($a["x"] ?? "dx"), " ", ($a["y"] ?? "dy"), " ", ($a["z"] ?? "dz"), "\n";

$v = [1, null, 3];
var_dump(isset($v[1]));        // false
var_dump(isset($v[2]));        // true
echo ($v[1] ?? "n"), " ", ($v[2] ?? "n"), "\n";

class Node {
    public ?int $val = null;
    public ?string $name = null;
    public ?Node $next = null;
}
$n = new Node();
var_dump(isset($n->val));      // false
var_dump(isset($n->name));     // false
var_dump(isset($n->next));     // false
echo ($n->val ?? -1), " ", ($n->name ?? "none"), "\n";
$n->val = 5;
$n->name = "root";
var_dump(isset($n->val));      // true
echo ($n->val ?? -1), " ", ($n->name ?? "none"), "\n";
