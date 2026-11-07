<?php
// preg_replace_callback(_array) honour PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL.
$c = 0;
echo preg_replace_callback('/b(c)/', function ($m) { return json_encode($m); }, 'abcd', -1, $c, PREG_OFFSET_CAPTURE), $c, "\n";
echo preg_replace_callback('/b(x)?(y)?/', function ($m) { return json_encode($m); }, 'abcd', -1, $c, PREG_UNMATCHED_AS_NULL), "\n";
echo preg_replace_callback('/b(x)?(y)?/', function ($m) { return json_encode($m); }, 'abcd', -1, $c, PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE), "\n";
echo preg_replace_callback('/b(x)?(c)/', function ($m) { return json_encode($m); }, 'abcd'), "\n";
echo preg_replace_callback('/(?<n>b)/', function ($m) { return json_encode($m); }, 'abcd', -1, $c, PREG_OFFSET_CAPTURE), "\n";
echo preg_replace_callback_array(['/b/' => function ($m) { return json_encode($m); }], 'abcd', -1, $c, PREG_OFFSET_CAPTURE), "\n";
echo json_encode(preg_replace_callback('/a/', fn ($m) => (string)$m[0][1], ['xa', 'yya'], -1, $c, PREG_OFFSET_CAPTURE)), "\n";
echo preg_replace_callback(['/a/', '/b/'], fn ($m) => (string)$m[0][1], 'ab', -1, $c, PREG_OFFSET_CAPTURE), "\n";
class K { public function f(string $s): string { return preg_replace_callback('/b/', function ($m) use ($s) { return (string)$this->p($m[0][1], $s); }, $s, -1, $n, \PREG_OFFSET_CAPTURE); } private function p(int $o, string $s): int { return $o + 100; } }
echo (new K)->f('abc'), "\n";
