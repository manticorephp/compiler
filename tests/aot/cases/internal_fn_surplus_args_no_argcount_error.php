<?php
// An internal function called with more arguments than it accepts returns normally instead of throwing ArgumentCountError.
try {
    var_dump(strlen("a", "b"));
} catch (ArgumentCountError $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
