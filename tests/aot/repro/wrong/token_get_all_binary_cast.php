<?php
// token_get_all() does not lex the (binary) cast as T_STRING_CAST
// issue: #358
echo token_name(token_get_all('<?php (binary) $a;')[1][0]), "\n";
echo token_name(token_get_all('<?php (Binary) $a;')[1][0]), "\n";
