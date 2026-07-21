<?php

// A bare `: array` (or `?array`) return whose empty-guard `return []` sits beside
// a concrete-element return must NOT erase the element/shape. `[]` is vec[unknown]
// (shape-agnostic in php); it should defer to the concrete sibling, not conflict
// to unknown (which read each element's pointer as a raw int/float).

function vecOrEmpty(bool $e): array { if ($e) return []; return explode(",", "a,b,c"); }
function assocOrEmpty(bool $e): array { if ($e) return []; return ["k" => "v", "j" => "w"]; }
function nullableArr(bool $e): ?array { if ($e) return null; return ["Content-Type" => "text/html"]; }
function builtFromParam(array $xs): array {
    if (!$xs) { return []; }
    $o = [];
    foreach ($xs as $x) { $o[] = strtoupper($x); }
    return $o;
}

var_dump(vecOrEmpty(false));
var_dump(vecOrEmpty(true));
var_dump(assocOrEmpty(false));
var_dump(assocOrEmpty(true));
var_dump(nullableArr(false));
var_dump(nullableArr(true));
var_dump(is_array(nullableArr(false)));
var_dump(builtFromParam(["a", "b"]));
var_dump(builtFromParam([]));

// index + count on the non-erased result
$h = nullableArr(false);
var_dump($h["Content-Type"]);
var_dump(count(assocOrEmpty(false)));
