<?php
function greet(string $n): string { return "hi $n"; }
function bye(string $n): string { return "bye $n"; }
$which = (count($argv) > 100) ? "bye" : "greet";
echo $which("bob"), "\n";
echo call_user_func($which, "sue"), "\n";
foreach (["greet", "bye"] as $f) { echo $f("x"), "\n"; }
