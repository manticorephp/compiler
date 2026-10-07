<?php
// token_get_all(..., TOKEN_PARSE) never throws ParseError
// issue: #65
try { token_get_all('<?php if (', TOKEN_PARSE); echo "no error\n"; } catch (ParseError $e) { echo "ParseError\n"; }
