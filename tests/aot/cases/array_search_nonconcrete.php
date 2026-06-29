<?php
// array_search over a NON-concrete (erased) vec haystack — the InlineClosures
// synthesis only fires on a concrete haystack, so this exercises the stdlib
// fallback. Before it existed the link failed (undefined @manticore_array_search).
function find(array $h, string $n) { return array_search($n, $h); }
var_dump(find(["apple", "banana", "cherry"], "banana"));
var_dump(find(["apple", "banana", "cherry"], "missing"));

function build(int $n): array {
    $a = [];
    for ($i = 0; $i < $n; $i++) { $a[] = "item" . $i; }
    return $a;
}
var_dump(array_search("item2", build(4)));

function mk(): array { return ["x", "y", "z"]; }
var_dump(array_search("y", mk()));

// concrete haystack still routes through the precise synthesis
var_dump(array_search("cherry", ["apple", "banana", "cherry"]));
