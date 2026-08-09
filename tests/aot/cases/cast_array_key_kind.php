<?php
// `(array)$x` keys are decided at RUNTIME, per php's per-kind rule: a list
// yields 0..n-1, a scalar yields the single key 0, an assoc keeps its own mix.
// Typing the cast `assoc<string, cell>` claimed otherwise, so `is_string($k)`
// FOLDED TO TRUE over a key the runtime hands back as a cell and the true arm
// bitcast that cell to a string pointer — a SIGSEGV inside
// __mir_rc_release_str, with nothing near the cause to read.
// The object kind is {@see cast_array_object_props}.

function kinds(mixed $v): string {
    $out = "";
    foreach ((array)$v as $k => $val) {
        $ks = is_string($k) ? $k : (string)$k;
        $out = $out . "[" . $ks . "=" . (is_string($k) ? "s" : "i") . "]";
    }
    return $out;
}

// Read back through a mixed-valued array so the argument arrives ERASED — a
// directly-typed argument lets inference answer the question the cast cannot.
$box = ["assoc" => ["a" => 1, 7 => 2], "list" => [10, 20], "scalar" => "q"];
echo kinds($box["assoc"]), "\n";
echo kinds($box["list"]), "\n";
echo kinds($box["scalar"]), "\n";
