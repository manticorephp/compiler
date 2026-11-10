<?php
// A bare `exit;` (no parentheses) throws "Undefined constant exit" instead of terminating.
// issue: #635
echo "a\n";
exit;
echo "b\n";
