<?php
// A local re-kinded inside switch arms is merged past the switch like an
// if/else merge: it read the head's int and printed the string's address.
function s(string $op, string $t): void {
    $x = 0;
    switch ($op) {
        case '>': $x = 5; break;
        default: $x = $t;
    }
    var_dump($x);
}
function i(string $op, string $t): void {
    $x = 0;
    if ($op === '>') { $x = 5; } else { $x = $t; }
    var_dump($x);
}
function l(array $ops, string $t): void {
    $x = 0;
    foreach ($ops as $op) {
        switch ($op) {
            case '>': $x = 5; break;
            default: $x = $t;
        }
    }
    var_dump($x);
}
s('=', '1'); i('=', '1'); l(['='], '1');
