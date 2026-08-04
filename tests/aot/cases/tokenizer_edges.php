<?php
// The corners: __halt_compiler's data tail, TOKEN_PARSE's member-name
// downgrade, T_BAD_CHARACTER, and every unterminated construct. All measured
// against php — note that an unterminated comment/string/heredoc is NOT an
// error to the tokenizer, it just runs to EOF.
//
// DIVERGENCE, deliberate: under TOKEN_PARSE php also parses and throws
// ParseError on invalid input. We do not — there is no parser at this level.
// Only valid sources appear below.
function dump(string $src, int $flags = 0): void
{
    foreach (token_get_all($src, $flags) as $t) {
        if (is_array($t)) { echo token_name($t[0]), '|', $t[2], '|', addcslashes($t[1], "\0..\37\\"), "\n"; }
        else { echo 'CHAR|', $t, "\n"; }
    }
    echo "--\n";
}

dump("<?php __halt_compiler(); junk \$\$\$\nmore");
dump("<?php __halt_compiler();tail");
dump("<?php \$a = Foo::class;");
dump("<?php \$a = Foo::class;", TOKEN_PARSE);
dump("<?php \$a = Foo::list;", TOKEN_PARSE);
dump("<?php class A { function list() {} }");
dump("<?php class A { function list() {} }", TOKEN_PARSE);
dump("<?php \$o->class;", TOKEN_PARSE);
dump("<?php \$a = \x01\x02;");
dump("<?php /* open");
dump("<?php \"open");
dump("<?php \$x = <<<EOT\nbody");
dump("<?php \$a = 1 ?>");
dump("plain html only");
