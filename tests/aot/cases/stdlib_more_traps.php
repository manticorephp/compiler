<?php

// mb_strcut, the two stream-layer answers, and dba_list — four of the T5 traps
// that need no subsystem behind them.

$s = "a\xc3\xa4\xe4\xb8\xadb";           // a, U+00E4 (2 bytes), U+4E2D (3), b
echo strlen($s), "\n";

// A cut never splits a sequence: the start snaps back, the end snaps back, and
// the length is measured from the SNAPPED start.
foreach ([[0, 1], [0, 2], [0, 3], [1, 2], [2, 2], [2, 4], [3, 3], [1, -1], [-3, 2], [0, 100], [5, 0]] as $p) {
    $cut = mb_strcut($s, $p[0], $p[1]);
    echo $p[0], ',', $p[1], ' -> ', bin2hex($cut), ' (', strlen($cut), ")\n";
}
echo bin2hex(mb_strcut($s, 2)), "\n";
var_dump(mb_strcut($s, 0, null) === $s);
echo bin2hex(mb_strcut($s, 1, 4, 'UTF-8')), "\n";
echo mb_strcut('plain ascii', 6, 5), "\n";
var_dump(mb_strcut('', 0, 5));

// A context's params: the options under php's own key.
$c = stream_context_create(['http' => ['method' => 'POST', 'header' => 'X: 1']]);
print_r(stream_context_get_params($c));
print_r(stream_context_get_params(stream_context_create()));

// A wrapper this build has can be restored; one it does not have cannot.
// php also emits a notice ("was never changed") or a warning ("never existed")
// alongside each answer and this build stays quiet — the documented
// no-warnings divergence, so only the return values are compared here.
var_dump(stream_wrapper_restore('http'));
var_dump(stream_wrapper_restore('file'));
var_dump(stream_wrapper_restore('nope'));

// Nothing is open, so nothing is listed.
var_dump(dba_list());
