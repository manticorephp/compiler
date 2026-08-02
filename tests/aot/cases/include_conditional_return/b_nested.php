<?php
// A return nested two levels deep AND inside a loop — the jump has to leave
// both. Also proves the statements after it in the same file are skipped, which
// is what dropping the statement (the old behaviour for a bare top-level
// return) got wrong in the opposite direction.

$GLOBALS['icr_log'][] = 'nested: start';

foreach ([1, 2, 3] as $v) {
    if ($v === 2) {
        $GLOBALS['icr_log'][] = "nested: returning at v=$v";
        return "stopped-at-$v";
    }
    $GLOBALS['icr_log'][] = "nested: iter $v";
}

$GLOBALS['icr_log'][] = 'nested: MUST NOT APPEAR';
return 'fellthrough';
