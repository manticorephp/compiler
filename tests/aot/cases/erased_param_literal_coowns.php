<?php
// A closure parameter that reaches the literal as an erased word (a raw
// array) is wrapped by the probing boxer; the literal must co-own the array
// it wraps, or the caller's release frees it under the returned literal.
function wrapAll(array $elements, callable $get): array
{
    $out = [];
    foreach ($elements as $element) { $out[] = $get($element); }
    return $out;
}
function build(): array
{
    $els = [];
    for ($i = 0; $i < 4; $i++) { $els[] = ['type' => 't' . $i, 'start' => $i]; }
    return wrapAll($els, fn (array $element): array => ['element' => $element]);
}
$r = build();
$noise = [];
for ($i = 0; $i < 64; $i++) { $noise[] = ['x' => str_repeat('z', 8) . $i, 'y' => $i * 7]; }
foreach ($r as $w) { echo $w['element']['type'], ':', $w['element']['start'], ' '; }
echo "\n", count($noise), "\n";
