<?php
// ext/tokenizer: the T_* ids are Zend's OWN numbers, so this case compares
// INTEGERS against the interpreter, not just names. Generated tables —
// tools/gen_tokenizer_tables.php.
var_dump(function_exists('token_get_all'), extension_loaded('tokenizer'), defined('T_CLASS'));
var_dump(T_STRING, T_WHITESPACE, T_INLINE_HTML, T_NAME_QUALIFIED, TOKEN_PARSE);
var_dump(token_name(T_DOC_COMMENT), token_name(59));

$src = "html<?php\nnamespace A\\B;\nuse \\Ns\\C;\nclass K extends \\Base implements namespace\\I {\n"
     . "    public(set) int \$p = 0;\n    public function m(int ...\$r): static { return \$this; }\n}\n"
     . "\$h = 0x1F + 0b1010 + 0o17 + 017 + 1_000 + 1.5e3 + 9223372036854775808 + 9223372036854775807;\n"
     . "\$i = (int)\$x + ( bool )\$y + (REAL)\$z + (void)\$w;\n"
     . "\$j = \$a <=> \$b ?? \$c ?: \$d; \$k = \$a ** 2; \$l = \$a &\$b; \$m = 1&2;\n"
     . "/** doc */ /**/ /***/ /**x*/ // line\n# hash\n#[Attr(1)]\n"
     . "\$o = match(1) { 1 => 2, default => 3 }; \$p = Foo::class; \$q = \$obj?->x;\n"
     . "enum E { case A; } \$e = enum;\nyield  from \$gen;\n?>\ntail";
foreach (token_get_all($src) as $t) {
    if (is_array($t)) { echo token_name($t[0]), '|', $t[0], '|', $t[2], '|', addcslashes($t[1], "\0..\37\\"), "\n"; }
    else { echo 'CHAR|', $t, "\n"; }
}
