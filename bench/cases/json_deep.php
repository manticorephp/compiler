<?php
// json_deep — nesting. php stops at $depth (512 by default) and answers null;
// neither manticore decoder has a limit at all, so the only thing standing
// between deep input and a stack overflow is luck. 400 stays inside php's limit
// so the two sides can agree on the VALUE while the recursion cost is measured.
$n = 400;
$doc = str_repeat("[", $n) . "1" . str_repeat("]", $n);
$acc = 0;
$reps = 2000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $v = json_decode($doc, true);
    $acc += is_array($v) ? 1 : 0;
}
echo $acc, "\n";
