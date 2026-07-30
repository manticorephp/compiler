<?php

// set_error_handler / restore_error_handler / trigger_error, including the
// handler's `false` return falling through to php's own printed diagnostic.

echo "start\n";

// No handler yet — php prints its own line to stdout.
trigger_error("plain notice");
trigger_error("a warning", E_USER_WARNING);
trigger_error("a deprecation", E_USER_DEPRECATED);

$prev = set_error_handler(function (int $level, string $msg, string $file, int $line): bool {
    echo "handled[", $level, "]: ", $msg, "\n";
    return true;
});
echo $prev === null ? "prev-null\n" : "prev-set\n";

trigger_error("through the handler", E_USER_WARNING);

// A handler that declines: php falls back to its own diagnostic.
set_error_handler(function (int $level, string $msg): bool {
    echo "declining: ", $msg, "\n";
    return false;
});
trigger_error("declined", E_USER_NOTICE);

restore_error_handler();
trigger_error("back to the first handler", E_USER_NOTICE);

restore_error_handler();
trigger_error("no handler again", E_USER_NOTICE);

// error_reporting() filters what gets printed, and returns the OLD mask.
$old = error_reporting(E_ALL & ~E_USER_NOTICE);
echo "old=", $old === E_ALL ? "all" : "other", "\n";
trigger_error("filtered out", E_USER_NOTICE);
trigger_error("still shown", E_USER_WARNING);
error_reporting($old);

// `@` suppresses the printed line but still records the error.
@trigger_error("silenced", E_USER_WARNING);
$last = error_get_last();
echo "last=", $last === null ? "none" : $last["message"], "\n";

echo "end\n";
