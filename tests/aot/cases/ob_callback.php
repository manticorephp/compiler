<?php

// Every callable shape php accepts as an ob handler, plus the phase argument.
function ph(string $b, int $p): string { return $b . "(p=" . $p . ")"; }

ob_start('ph');
echo "A";
ob_end_flush();
echo "|end_flush\n";

ob_start('ph');
echo "B";
$r = ob_get_flush();
echo "|get_flush returns the ORIGINAL: ", $r, "\n";

// START is consumed by the first handler call, so the second reports 8, not 9.
ob_start('ph');
echo "C";
ob_flush();
echo "D";
ob_end_flush();
echo "|two-phase\n";

// ob_clean consumes START without ever calling the handler.
ob_start('ph');
echo "E";
ob_clean();
echo "F";
ob_end_flush();
echo "|after-clean\n";

class Wrap
{
    public function up(string $b, int $p): string { return "[" . $b . "]"; }
}
$w = new Wrap();
ob_start([$w, 'up']);
echo "method";
ob_end_flush();
echo "|\n";

ob_start(fn(string $b, int $p): string => strrev($b));
echo "closure";
ob_end_flush();
echo "|\n";

// A handler returning false leaves the buffer untouched.
ob_start(fn(string $b, int $p): string|false => false);
echo "untouched";
ob_end_flush();
echo "|\n";

// Output the HANDLER produces is DISCARDED — not forwarded downstream, and not
// folded back into the buffer it is handling.
function noisy(string $b, int $p): string { echo "[side]"; return $b . "#"; }
ob_start();
ob_start('noisy');
echo "re";
ob_end_flush();
$o = ob_get_clean();
echo "nested=[", $o, "]\n";

// A handler still sees ITS OWN level, because php pops after the call.
function peeker(string $b, int $p): string { return $b . "{lvl=" . ob_get_level() . "}"; }
ob_start('peeker');
echo "L";
ob_end_flush();
echo "|\n";

// The clean family never invokes the handler at all.
ob_start('ph');
echo "G";
$g = ob_get_clean();
echo "get_clean=[", $g, "]\n";
ob_start('ph');
echo "H";
ob_end_clean();
echo "end_clean ran, nothing printed\n";
