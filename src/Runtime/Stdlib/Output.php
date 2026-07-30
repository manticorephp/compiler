<?php

/**
 * The one bit of output state the stdlib has to own: whether a byte has left
 * the program yet.
 *
 * php's CLI SAPI tracks this for real — after the first byte, `header()`,
 * `session_start()`, `session_id($x)` and every `ini_set('session.*')` fail —
 * so the flag is not a web-only concept, it is observable in a plain script.
 *
 * It lives here, not in the SAPI prelude, because `ini_set` has to read it and
 * a stdlib function may never demand a prelude. A scalar `static` is exactly
 * what survives inside the stdlib .o; the prelude's output sink flips it.
 *
 * $op: 0 reads, 1 marks output as gone out.
 */
function __mc_out_sent(int $op): int
{
    static $sent = 0;
    if ($op === 1) {
        $sent = 1;
    }
    return $sent;
}
