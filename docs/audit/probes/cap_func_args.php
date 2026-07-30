<?php
// @epic: language-core
// @why: NOT a SAPI gap -- func_num_args()/func_get_arg() are language core, and
//       symfony/console alone calls func_num_args 15 times to detect which
//       optional arguments a caller actually passed (Command::__construct,
//       addOption, addArgument). Absent, so every such call link-stubs to 0 and
//       the "argument was omitted" branch is taken always.

function capArgs(int $a = 1, int $b = 2, int $c = 3): string
{
    $n = func_num_args();
    $got = [];
    for ($i = 0; $i < $n; $i++) { $got[] = func_get_arg($i); }
    return $n . ':' . implode(',', $got);
}

var_dump(capArgs());
var_dump(capArgs(9));
var_dump(capArgs(9, 8));
var_dump(capArgs(9, 8, 7));

function capAll(): string { return implode('|', func_get_args()); }
var_dump(capAll('x', 'y', 'z'));
