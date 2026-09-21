<?php
// A by-ref out-param takes the superglobal cell's ADDRESS; the callee writes
// back through the PARAM's type, so the cell's decl-flavour release must still
// agree with what the write-back stored.
class O { public int $n = 1; }
function fill(array &$out): void { $out['k'] = new O; $out['k']->n = count($out); }
// The same call through a reference ALIAS of the superglobal: the alias is
// backed by the same cell, so its address is the cell's too.
function via(): void { $s = &$_GET; fill($s); }
for ($round = 0; $round < 3; $round++) {
    fill($_GET);
    echo $_GET['k']->n, "\n";
    $_GET = ['z' => $round];
    print_r($_GET);
    fill($_GET);
    echo $_GET['k']->n, ' ', count($_GET), "\n";
    $_GET = ['z' => $round];
    via();
    echo $_GET['k']->n, ' ', count($_GET), "\n";
    $_GET = ['z' => $round];
}
echo "done\n";
