<?php
// The binary flag on an interpolating "…" and a quoted heredoc label stays on its token;
// `<<<` that opens no heredoc is `<<` + `<`, prefixed or not.
$src = "<?php b\"a\$x\"; B`x`; b<<<\"E\"\nq\nE;\nB<<<'E'\nq\nE;\n bar'x' b<<<1; <<<1; <<< \"x; b<<E;";
foreach (token_get_all($src) as $t) {
    echo is_array($t) ? token_name($t[0]) . ' ' . json_encode($t[1]) . ' ' . $t[2] : 'CH ' . $t, "\n";
}
foreach (PhpToken::tokenize("<?php b\"a\$x\";") as $t) {
    echo $t->id, ' ', json_encode($t->text), "\n";
}
