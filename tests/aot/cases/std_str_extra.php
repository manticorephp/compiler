<?php
set_error_handler(function ($n, $m) { throw new ValueError($m); }, E_WARNING);
function t(callable $f, ...$a) { try { var_dump($f(...$a)); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
foreach (['a', 'z', 'Az', 'zz', 'Zz', '9', '99', 'a9', 'Zz9', '', 'a-b'] as $s) { echo "inc($s) "; t('str_increment', $s); }
foreach (['b', 'a', 'aa', 'A0', '10', '100', '0', '09', 'Ab', '', '!'] as $s) { echo "dec($s) "; t('str_decrement', $s); }
foreach (["a b=c", "caf\xc3\xa9", str_repeat('x', 100), "line\r\nnext", "tab\t \r\n", "=", ''] as $s) {
    $e = quoted_printable_encode($s); echo json_encode($e), ' ', json_encode(quoted_printable_decode($e) === $s), "\n";
}
foreach (["a=\r\nb", "=41=42", "x=\n y", "=4G", "=", "a=  \r\nb", "=3d"] as $s) { echo json_encode(quoted_printable_decode($s)), "\n"; }
foreach (['', 'a', 'abc', 'hello world', str_repeat('xyz', 40)] as $s) {
    $e = convert_uuencode($s); echo json_encode($e), ' ', json_encode(convert_uudecode($e) === $s), "\n";
}
t('convert_uudecode', 'abc');
t('convert_uudecode', '');
foreach (['Robert', 'Rupert', 'Ashcraft', 'Tymczak', 'Pfister', 'Honeyman', '', 'a', 'Lloyd', '123'] as $s) { echo soundex($s), ' '; }
echo "\n";
foreach (['Thompson', 'Knight', 'Schwartz', 'Which', 'Xavier', 'Science', 'Dodge', 'Ghost', 'Laugh', 'Bridge', 'Thumb', '', 'Phone'] as $s) { echo metaphone($s), ' '; }
echo metaphone('Thompson', 2), ' ', metaphone('Aebersold', 3), "\n";
t('metaphone', 'a', -1);
foreach (['', ' ', '1', '+1', '-1', '1k', '1K', ' 1 k', '1m', '1G', '012', '0x1f', '0b11', '0o17', '0x1fk'] as $s) { echo json_encode($s), '=>'; t('ini_parse_quantity', $s); }
foreach (['1kb', '1.5k', '0o', 'k', '1t', '0a', '9223372036854775808', "1\t2"] as $s) { echo json_encode($s), '=>'; t('ini_parse_quantity', $s); }
