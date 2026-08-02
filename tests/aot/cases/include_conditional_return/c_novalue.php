<?php
// A guarded return with NO value: php's `require` of it evaluates to NULL (an
// int(1) is what a file with no return at all gives), and the rest of the file
// must still be skipped.

$GLOBALS['icr_log'][] = 'novalue: start';

if (strlen("xy") === 2) {
    return;
}

$GLOBALS['icr_log'][] = 'novalue: MUST NOT APPEAR';
