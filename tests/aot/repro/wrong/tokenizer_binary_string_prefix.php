<?php
// token_get_all() splits the binary flag off b'x', b"x" and B<<<E into a T_STRING instead of one token
// issue: #371
foreach ([token_get_all("<?php \$a = b'x'; \$b = B\"y\"; \$c = b<<<E\nz\nE;\n")] as $toks) {
    foreach ($toks as $t) {
        echo is_array($t) ? token_name($t[0]) . ' ' . json_encode($t[1]) : 'CH ' . $t, "\n";
    }
}
