<?php

// curl_share_*, CURLOPT_XFERINFOFUNCTION, curl_pause and curl_upkeep.
//
// The share handle is the one option whose VALUE is another handle object:
// libcurl keeps only a raw CURLSH*, so the easy handle has to co-own the share
// or a dropped `$sh` would be freed out from under an in-flight transfer.
//
// The progress trampoline is the fourth and last of them, and the only one whose
// arguments are all integers — curl_off_t is 64-bit on every LP64 target, so it
// rides the uniform i64 ABI with nothing to convert.

$a = \sys_get_temp_dir() . '/mc_curl_sh_a.txt';
$b = \sys_get_temp_dir() . '/mc_curl_sh_b.txt';
\file_put_contents($a, \str_repeat("a", 2048));
\file_put_contents($b, \str_repeat("b", 4096));

$sh = \curl_share_init();
echo "-- share --\n";
echo "dns:      ", \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS) ? 'true' : 'false', "\n";
echo "connect:  ", \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT) ? 'true' : 'false', "\n";
echo "cookie:   ", \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE) ? 'true' : 'false', "\n";
echo "unshare:  ", \curl_share_setopt($sh, CURLSHOPT_UNSHARE, CURL_LOCK_DATA_COOKIE) ? 'true' : 'false', "\n";
echo "errno:    ", \curl_share_errno($sh), "\n";
echo "strerror: ", \var_export(\curl_share_strerror(0), true), "\n";

$ca = \curl_init('file://' . $a);
\curl_setopt($ca, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ca, CURLOPT_SHARE, $sh);
$cb = \curl_init('file://' . $b);
\curl_setopt($cb, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($cb, CURLOPT_SHARE, $sh);

echo "body a:   ", \strlen(\curl_exec($ca)), "\n";
echo "body b:   ", \strlen(\curl_exec($cb)), "\n";
echo "errno a:  ", \curl_errno($ca), " b: ", \curl_errno($cb), "\n";

// -- progress ------------------------------------------------------------
// php hands the callback ($handle, $dltotal, $dlnow, $ultotal, $ulnow) and
// treats a NON-ZERO return as "abort", which surfaces as
// CURLE_ABORTED_BY_CALLBACK (42).
echo "-- progress --\n";
// ⚠ The callback records a BOOL rather than `$maxDl = $dln`. Assigning a value
// that arrived through a `mixed` parameter straight into a by-ref captured local
// stores the tagged word raw — see tests/aot/cases/array_erased_elem_repr_gap.php.
// Comparing and doing arithmetic on it, as below, unboxes correctly.
$seen = 0;
$sawFull = false;
$cc = \curl_init('file://' . $b);
\curl_setopt($cc, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($cc, CURLOPT_NOPROGRESS, false);
\curl_setopt($cc, CURLOPT_XFERINFOFUNCTION, function ($h, $dlt, $dln, $ult, $uln) use (&$seen, &$sawFull) {
    $seen = $seen + 1;
    if ($dln >= 4096) { $sawFull = true; }
    return 0;
});
$body = \curl_exec($cc);
echo "len:      ", \strlen($body), "\n";
echo "called:   ", $seen > 0 ? 'yes' : 'no', "\n";
echo "dlnow:    ", $sawFull ? 'full' : 'partial', "\n";
echo "errno:    ", \curl_errno($cc), "\n";

// A callback that aborts.
$cd = \curl_init('file://' . $b);
\curl_setopt($cd, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($cd, CURLOPT_NOPROGRESS, false);
\curl_setopt($cd, CURLOPT_XFERINFOFUNCTION, function ($h, $dlt, $dln, $ult, $uln) {
    return 1;
});
$r = \curl_exec($cd);
echo "abort:    ", \var_export($r, true), " errno=", \curl_errno($cd), "\n";

// -- pause / upkeep ------------------------------------------------------
echo "-- pause/upkeep --\n";
$ce = \curl_init();
// CURLE_BAD_FUNCTION_ARGUMENT (43): there is no transfer to pause on a handle
// that has never run. php reports the code rather than throwing.
echo "pause:    ", \curl_pause($ce, CURLPAUSE_CONT), "\n";
echo "upkeep:   ", \curl_upkeep($ce) ? 'true' : 'false', "\n";
echo "upkeep a: ", \curl_upkeep($ca) ? 'true' : 'false', "\n";

\unlink($a);
\unlink($b);
