<?php
final class P { public int $n = 7; public function __destruct() { echo "dtor\n"; } }
function un(string $s): mixed { return unserialize($s); }
/** @return array<int, mixed> */
function pair(): mixed { return [1, 'two']; }

$h = (function (string $s): P { return unserialize($s); })(serialize(new P()));
echo $h->n, "\n";
$f = function (string $s): P { return un($s); };
$g = $f(serialize(new P()));
echo $g->n, "\n";
$a = (fn (string $s): P => unserialize($s))(serialize(new P()));
echo $a->n, "\n";
$arr = (function (): array { return pair(); })();
echo count($arr), ' ', $arr[1], "\n";
$objs = array_map(function (string $s): P { return unserialize($s); }, [serialize(new P())]);
echo $objs[0]->n, "\n";
$c = call_user_func($f, serialize(new P()));
echo $c->n, "\n";
unset($h, $g, $a, $objs, $c);
echo "end\n";
