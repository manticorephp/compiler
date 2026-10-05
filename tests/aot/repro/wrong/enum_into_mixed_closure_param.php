<?php
// An enum argument to a dynamically-called closure's `mixed` param arrives as its ordinal
// issue: #38
enum E { case A; case B; }
$fs = [function (mixed $v): void { var_dump($v instanceof E); var_dump($v); }];
$fs[0](E::B);
