<?php
// A local read but assigned nowhere in its function reads NULL (php warns on a
// plain read; empty() / isset() / ?? are silent), at top level and in a function.
function view(): void {
    if (!empty($notices)) {
        foreach ($notices as $n) { echo $n, "\n"; }
    }
    echo $title ?? 'untitled', "\n";
    var_dump(isset($missing));
}
view();
if (!empty($top)) { echo "never\n"; }
echo "done\n";
