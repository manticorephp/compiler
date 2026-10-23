<?php
// __McTok (prelude tokenizer) does not lex the nullsafe operator in simple interpolation ("$a?->b"): the `?->b` stays string text; Zend lexes T_NULLSAFE_OBJECT_OPERATOR + T_STRING.
// Zend:      "$a?->b" -> T_VARIABLE "$a", T_NULLSAFE_OBJECT_OPERATOR "?->", T_STRING "b"
// Manticore: "$a?->b" -> T_VARIABLE "$a", T_ENCAPSED_AND_WHITESPACE "?->b"
// Effect: explicit_string_variable wraps only `$a` (`{$a}?->b`), upstream `{$a?->b}`.
foreach (token_get_all('<?php $z = "$a?->b";') as $t) {
    echo is_array($t) ? token_name($t[0]) . json_encode($t[1]) : json_encode($t), ' ';
}
echo "\n";
