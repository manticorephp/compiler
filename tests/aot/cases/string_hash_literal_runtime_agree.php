<?php
// A literal key's hash is folded at compile time and a runtime key's is
// computed by the runtime: the two must agree for every length around the
// 8-byte word step (and the reflection lookup shares the same cached word).
$m = ['' => 0, 'a' => 1, 'abcdefg' => 7, 'abcdefgh' => 8, 'abcdefghi' => 9,
      'abcdefghijklmnop' => 16, 'abcdefghijklmnopq' => 17, str_repeat('z', 40) => 40];
$keys = ['', 'a', 'abcdefg', 'abcdefgh', 'abcdefghi', 'abcdefghijklmnop', 'abcdefghijklmnopq', str_repeat('z', 40)];
foreach ($keys as $k) {
    $rt = substr($k . '#', 0, -1);
    echo strlen($rt), ' ', $m[$rt] ?? 'MISS', ' ', isset($m[$rt]) ? 'y' : 'n', "\n";
}
$big = [];
for ($i = 0; $i < 3000; $i++) { $big['key-' . $i . '-' . str_repeat('q', $i % 23)] = $i; }
$hits = 0;
for ($i = 0; $i < 3000; $i++) { if (($big['key-' . $i . '-' . str_repeat('q', $i % 23)] ?? -1) === $i) { $hits++; } }
echo $hits, "\n";
final class SomeLongReflectedClassName {}
echo (new ReflectionClass('SomeLongReflectedClassName'))->getName(), "\n";
