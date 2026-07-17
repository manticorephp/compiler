<?php
/** shape of resolve_sources(): ?array — null arm + concrete array arm */
function pick(bool $e): ?array {
    if ($e) { return null; }
    return ['a', 'b'];
}
$r = pick(false);
if ($r === null) { echo "null\n"; } else { echo "count=", count($r), "\n"; }
foreach ($r as $x) { echo "item=", $x, "\n"; }
echo $r[0], "\n";
var_dump(pick(true));
echo "done\n";
