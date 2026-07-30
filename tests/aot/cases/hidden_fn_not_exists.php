<?php

// The Windows SAPI stubs LINK but must read as absent, exactly as they are on
// any unix php build — otherwise a program guarded on function_exists() takes
// the Windows path. The calls below are reached through a VALUE guard the
// folder cannot see, which is the whole reason the bodies have to exist.

echo \function_exists('sapi_windows_cp_set') ? "1" : "0", "\n";
echo \function_exists('sapi_windows_cp_get') ? "1" : "0", "\n";
echo \function_exists('sapi_windows_cp_conv') ? "1" : "0", "\n";
echo \function_exists('sapi_windows_vt100_support') ? "1" : "0", "\n";

// symfony/console's shape verbatim: the ternary folds to 0, so the guarded
// call never runs — but it is still emitted, and must link.
$cp = \function_exists('sapi_windows_cp_set') ? sapi_windows_cp_get() : 0;
echo $cp, "\n";
if ($cp) {
    sapi_windows_cp_set($cp);
    echo sapi_windows_cp_conv(sapi_windows_cp_get('oem'), $cp, "x"), "\n";
}
echo "done\n";
