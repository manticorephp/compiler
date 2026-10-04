<?php
// A typed by-value param handed to a `?int &$c` (a cell) param is one word the
// callee rewrites as an int, so the caller's slot is a cell — but the param
// still ARRIVES in its declared representation and turns cell at entry. The
// slot was read as a cell while it held the raw string (SIGSEGV), an int param
// printed float(1), and a known closure's by-ref cell param never widened the
// caller at all (the int came back rendered as a string).
function inc(?int &$c): void { $c = ($c ?? 0) + 1; }
function run_s(?string $s): void { inc($s); var_dump($s); }
function run_i(int $s): void { inc($s); inc($s); var_dump($s); }
run_s('5'); run_s(null); run_i(4);

function viaClosure(?string $s, ?string $t): void {
    $inc = function (?int &$c): void { $c = ($c ?? 0) + 1; };
    $inc($s); var_dump($s);
    $inc($t); var_dump($t);
    $u = $t === null ? null : '3'; $inc($u); var_dump($u);
}
viaClosure('5', null);
viaClosure(null, '9');
