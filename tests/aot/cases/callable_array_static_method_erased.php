<?php
// A static method reached through an object in a callable array or an erased receiver takes no $this.
class R { public static function fl(string $l, int $n = 1): string { return $l . $n; } }
$o = new R;
echo $o->fl('a'), "\n";
$f = [$o, 'fl']; echo $f('d'), "\n";
function q(mixed $c) { return $c('z', 5); }
echo q([$o, 'fl']), "\n";
echo call_user_func([$o, 'fl'], 'e', 3), "\n";
