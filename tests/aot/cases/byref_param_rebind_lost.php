<?php
// `$s = &$other` on a by-ref parameter does not rebind it: the next write still lands in the caller's variable.
function h(string &$s): void { $other = "o"; $s = &$other; $s = "zz"; echo $other, "\n"; }
$b = "b"; h($b); echo $b, "\n";
