<?php
$s = [['s' => 0, 'e' => 'q']]; $t = array_map(fn($i) => $i, $s); $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = array_map(fn($i) => $i, $s); $t[0]['e'] = 'r'; var_dump($s[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = array_values($s); $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = array_filter($s); $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = []; foreach ($s as $r) { $t[] = $r; } $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = $s; $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$s = [['e' => 'q']]; $t = array_map(fn($i) => $i, $s); $s[0]['e'] = 'r'; var_dump($t[0]['e']);
$rows = array_map(fn($i) => $i, [['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']]); $rows[1]['b'] = 'z'; echo $rows[0]['b'], $rows[1]['b'], "\n";
$s = [['s' => 0, 'e' => 'q']]; $t = array_values($s); $t[0]['e'] = 'r'; var_dump($s[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = array_filter($s); $t[0]['e'] = 'r'; var_dump($s[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = []; foreach ($s as $r) { $t[] = $r; } $t[0]['e'] = 'r'; var_dump($s[0]['e']);
$s = [['s' => 0, 'e' => 'q']]; $t = $s; $t[0]['e'] = 'r'; var_dump($s[0]['e']);
