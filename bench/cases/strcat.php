<?php
// strcat — 30 M-iteration string append (buffer growth + copy). Runtime bound
// (* $argc) so strlen can't be folded without running the appends.
//
// @rss-scales — the WORKING SET is the built string, so RSS is meant to track
// the iteration count (php is ~2.2x the final length, we are ~2.3x: the realloc
// peak holds old+new during a grow). Not a leak; LEAK=1 must not call it one.
$n = 30000000 * $argc;
$s = "";
for ($i = 0; $i < $n; $i++) {
    $s .= "x";
}
echo strlen($s), "\n";
