<?php
// `array_keys()` over a STATICALLY string-keyed array hands its keys back RAW,
// not NaN-boxed. Boxed, every typed consumer was wrong: `preg_grep(string
// $pattern, array $array /* string[] */)` reads each element as a raw pointer,
// so preg_match got a tag-4 word and dereferenced the tag bits. symfony's
// `Application::find()` does exactly `preg_grep(…, array_keys($this->commands))`,
// so `./app <unknown-command>` SIGSEGV'd before printing anything.
//
// A mixed-keyed or erased source still answers cells — those keys really are
// int-or-string — and the result co-owns every key string it borrowed.

$map = ['config:set' => 1, 'list:items' => 2, 'greet' => 3];
$keys = array_keys($map);

echo implode(',', $keys), "\n";
echo count(preg_grep('/^config/', $keys)), "\n";
echo implode(',', preg_grep('/:/', $keys)), "\n";
echo count(preg_grep('/^z/', $keys)), "\n";

// The same shape through a user function with a string[] docblock.
/** @param string[] $names */
function firstMatching(array $names, string $needle): string
{
    foreach ($names as $n) {
        if (str_contains($n, $needle)) { return $n; }
    }
    return '';
}
echo firstMatching($keys, 'list'), "\n";
echo firstMatching(array_keys(['a' => 1, 'bb' => 2]), 'bb'), "\n";

// Int keys stay ints, and a values array is untouched.
$vals = array_values($map);
echo array_sum($vals), "\n";
echo implode('|', array_keys([5 => 'x', 9 => 'y'])), "\n";

// A cell-valued array into a string[] param rebuilds too.
$rec = ['a' => 'one', 'b' => 'two'];
$mixed = [];
foreach ($rec as $k => $v) { $mixed[] = $v; }
echo firstMatching($mixed, 'tw'), "\n";
echo "done\n";
