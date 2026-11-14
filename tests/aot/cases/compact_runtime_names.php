<?php
// compact() with names held in variables, and nested arrays.
function clauses(): array {
    $fields = 'id';
    $join = '';
    $where = 'a = 1';
    $limits = 'LIMIT 10';
    $pieces = ['fields', 'join', 'where', 'limits'];
    return compact($pieces);
}
var_dump(clauses());
function nested(): array {
    $a = 1; $b = [2]; $c = 'c';
    $more = ['b', ['c']];
    return compact('a', $more);
}
var_dump(nested());
