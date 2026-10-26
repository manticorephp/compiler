<?php
if ($a) { echo 1; } elseif ($b) echo 2; else { return; }
while ($x): $x--; endwhile;
for ($i = 0; $i < 3; $i++);
foreach ($l as $k => $v) { continue 2; }
do { break; } while (0);
static $s = 1;
global $g, $h;
throw $e;
goto end;
end:
declare(ticks=1);
switch ($n) { case 1: echo 1; break; default: }
try { f(); } catch (A|B $e) { g(); } finally { h(); }
if ($c): echo 3; else: echo 4; endif;
?>
hi
<?php
unset($u);
