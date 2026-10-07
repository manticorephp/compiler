<?php
// Arrays of enums / closures compare by carrier identity
// issue: #36
enum E { case A; }
var_dump([E::A] == [E::A], [E::A] === [E::A]);
$f = function () {};
var_dump([$f] == [$f], [$f] === [$f]);
