<?php
// __McTok (prelude tokenizer) lexes a hex / binary / octal / underscored index of a simple string offset ("$a[0x1F]") as T_NUM_STRING "0" + T_STRING "x1F"; Zend makes one T_NUM_STRING.
// Zend:      "$a[0x1F]" -> T_VARIABLE "$a", "[", T_NUM_STRING "0x1F", "]"      (same for 0b11, 0o7, 1_0)
// Manticore: "$a[0x1F]" -> T_VARIABLE "$a", "[", T_NUM_STRING "0", T_STRING "x1F", "]"
// Effect: explicit_string_variable quotes the "T_STRING before ]" -> `{$a[0'x1F']}` (upstream leaves `{$a[0x1F]}`).
foreach (token_get_all('<?php $z = "$a[0x1F] $b[0b11] $c[0o7] $d[1_0]";') as $t) {
    echo is_array($t) ? token_name($t[0]) . json_encode($t[1]) : json_encode($t), ' ';
}
echo "\n";
