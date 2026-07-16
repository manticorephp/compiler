<?php
// `global $g; $g[] = v` is a STORE_ELEMENT, not a StoreLocal, so the cross-scope
// global type join never saw it: an array global filled only by element stores
// kept an ERASED element and the read guessed a repr — implode over a string
// global printed `2.1e-314`. The element must come from the stores themselves.

$list = [];
function push(string $s): void
{
    global $list;
    $list[] = $s;
}
push("a");
push("b");
echo implode(",", $list), "\n";
var_dump($list[0]);

// A string-KEYED store makes an assoc, not a vec: typing it a vec would read
// each string key as an int index and render it as its pointer.
$map = [];
function put(string $k, string $v): void
{
    global $map;
    $map[$k] = $v;
}
put("k", "v");
put("j", "w");
foreach ($map as $k => $v) {
    echo $k, "=", $v, "\n";
}

// Mixed appends collapse to a tagged cell; floats and a 2^50 int (which a
// raw/tagged mixup would return as 0) round-trip by tag.
$vals = [];
function add($v): void
{
    global $vals;
    $vals[] = $v;
}
add("s");
add(1);
add(2.5);
add(1 << 50);
var_dump($vals);

// Value semantics: a copy of a global array is independent.
$src = [];
function seed(): void
{
    global $src;
    $src[] = 1;
}
seed();
$copy = $src;
$copy[] = 2;
echo count($src), " ", count($copy), "\n";
