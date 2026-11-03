<?php
// function_exists with a runtime name: first/last/middle entries and misses on
// every side of the sorted name table.
function aaa_first(): void {}
function mmm_middle(): void {}
function zzz_last(): void {}
function b2(): void {}
function _underscore_lead(): void {}
function a(): void {}
function ab(): void {}
function abc(): void {}

$names = [
    'aaa_first', 'mmm_middle', 'zzz_last', 'b2', '_underscore_lead', 'a', 'ab', 'abc',
    'aa', 'abcd', 'abb', 'mmm_middl', 'mmm_middlee', 'zzz_las', 'zzzz', '0', '',
    'no_such_function', 'strlen', 'array_map', 'not_a_builtin_xyz',
];
foreach ($names as $n) {
    echo $n === '' ? '(empty)' : $n, ' ', function_exists($n) ? 'yes' : 'no', "\n";
}
