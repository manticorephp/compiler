<?php
use Manticore\Ds\Map;
/** @var Map<string,int> $m */
$m = new Map();
$m->set("a", 1);
foreach (["x", 2.5, null] as $bad) {
    try { $m->set("b", $bad); echo "kept\n"; } catch (TypeError $e) { echo "TypeError\n"; }
}
try { $m["c"] = "y"; echo "kept\n"; } catch (TypeError $e) { echo "TypeError\n"; }
var_dump($m->get("a"));
echo count($m), "\n";
