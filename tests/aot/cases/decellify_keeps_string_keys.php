<?php
// A cell-element array stored into a local typed as a list (`vec[bool]`) is
// converted element by element; the conversion appended, so string and sparse
// keys were renumbered — array_key_first answered 0 where php answers '|'
// (php-cs-fixer's TypeExpression wrote `null0Expr[]`).
function glue(mixed $a, mixed $b): mixed {
    $s = ['|' => $a, '&' => $b];
    /** @var list<bool> $t */
    $t = array_filter($s);
    return array_key_first($t);
}
var_dump(glue(true, false), glue(false, true));
function sparse(mixed $a, mixed $b, mixed $c): array {
    $s = [5 => $a, 9 => $b, 12 => $c];
    /** @var list<int> $t */
    $t = array_filter($s);
    return array_keys($t);
}
var_dump(sparse(1, 0, 3));
