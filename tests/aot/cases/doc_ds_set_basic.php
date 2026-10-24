<?php

use Manticore\Ds\Set;

$tags = new Set();
foreach (['php', 'llvm', 'php', 'aot'] as $tag) {
    $tags->add($tag);           // a duplicate is ignored
}

echo count($tags), "\n";        // 3
var_dump($tags->has('llvm'));
echo implode(',', $tags->toArray()), "\n";   // insertion order

$other = new Set();
$other->add('aot');
$other->add('jit');

echo implode(',', $tags->union($other)->toArray()), "\n";
echo implode(',', $tags->intersect($other)->toArray()), "\n";
echo implode(',', $tags->diff($other)->toArray()), "\n";

$tags->remove('php');
try {
    $tags->remove('php');
} catch (OutOfBoundsException $e) {
    echo $e->getMessage(), "\n";
}
