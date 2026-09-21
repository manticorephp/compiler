<?php
// A store through a TWO-hop reference alias (`$s = &$_SESSION; $t = &$s;
// $t = v`) lands in the superglobal's cell, so the module-wide store scan must
// judge it through the chain: `$t = $o` puts an OBJECT in an assoc-decl'd cell,
// and the next whole store's decl-flavour walker would have released the
// object as an array.
class O { public int $n = 0; }
function two(int $i): void
{
    $s = &$_SESSION;
    $t = &$s;
    $t = ['n' => $i, 'name' => 'user' . $i];
    $t['extra'] = str_repeat('e', 8) . $i;
}
function obj(int $i): void
{
    $s = &$_SESSION;
    $t = &$s;
    $o = new O;
    $o->n = $i;
    $t = $o;
}
for ($i = 0; $i < 3; $i++) {
    two($i);
    echo $_SESSION['n'], ' ', $_SESSION['name'], ' ', $_SESSION['extra'], "\n";
    obj($i);
    echo $_SESSION->n, "\n";
}
echo "done\n";
