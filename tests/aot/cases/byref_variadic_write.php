<?php
// A by-ref variadic method parameter writes int, string, null and type-changing values back.
final class Stmt {
    public function put(string $types, mixed &...$vars): bool {
        foreach ($vars as $i => &$v) {
            $v = match ($types[$i]) { 'i' => 42 + $i, 's' => "s$i", 'n' => null, 'd' => 1.5 };
        }
        return true;
    }
}
$s = new Stmt();
$a = 'str'; $b = 7; $c = 3; $d = null;
var_dump($s->put('isnd', $a, $b, $c, $d));
var_dump($a, $b, $c, $d);
$s->put('ni', $a, $u);
var_dump($a, $u);
$row = [1, 2];
$s->put('si', $row[0], $row[1]);
var_dump($row);
function put2(string $types, mixed &...$vars): void { for ($i = 0; $i < count($vars); $i++) { $vars[$i] = $types[$i] === 'i' ? $i : "x$i"; } }
$p = null; $q = 1;
put2('si', $p, $q);
var_dump($p, $q);
