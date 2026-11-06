<?php
// A first-class callable or array callable naming a static method through an object variable passes the wrong arguments.
// issue: #573
class R { public static function fl(string $l, int $n = 1): string { return implode('_', [$l, 'x']) . $n; } }
$o = new R;
$c = $o->fl(...); echo $c('f'), "\n";
$f = [$o, 'fl']; echo $f('d'), "\n";
echo call_user_func([$o, 'fl'], 'e', 3), "\n";
