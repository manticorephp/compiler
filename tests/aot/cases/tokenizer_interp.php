<?php
// String interpolation and heredoc drive a MODE STACK. Note what is NOT here:
// heredoc bodies keep their raw indentation and the closing marker's indent
// belongs to T_END_HEREDOC's own text — php does no flexible-indent stripping
// at the token level.
$src = "<?php\n\$a = \"plain\"; \$b = 'sq \\' esc';\n"
     . "\$c = \"hi \$name and {\$obj->prop} and \${bare} end\";\n"
     . "\$d = \"idx \$arr[0] and \$arr[key] and \$o->p more\";\n"
     . "\$e = <<<EOT\n  body \$v line\n  EOT;\n"
     . "\$f = <<<'NOW'\n  raw \$v not interpolated\n  NOW;\n"
     . "\$g = \"esc \\n \\\$x \\\\ done\";\n";
foreach (token_get_all($src) as $t) {
    if (is_array($t)) { echo token_name($t[0]), '|', $t[2], '|', addcslashes($t[1], "\0..\37\\"), "\n"; }
    else { echo 'CHAR|', $t, "\n"; }
}
