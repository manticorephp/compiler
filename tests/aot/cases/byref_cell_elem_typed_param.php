<?php
// A cell element handed to a typed by-ref param goes through a scratch slot and
// is re-boxed by the PARAM's kind: as `vec[cell]` a string came back as
// `array(24945)`, and the callee overwrote a null cell with a raw object
// pointer (then released the boxed null — SIGSEGV).
final class Big { public function __construct(public string $s) {} }
function put(?Big &$slot, string $s): void { $slot = new Big($s); }
function app(string &$x): void { $x .= 'a'; }
function obj(?stdClass &$x): void { $x = new stdClass; $x->v = 5; }

$a = [null, 'q'];
put($a[0], 'one'); echo $a[0]->s, "\n";
put($a[0], 'two'); echo $a[0]->s, "\n";
app($a[1]); var_dump($a[1]);
$b = [null, 'q'];
obj($b[0]); var_dump($b[0]);
// php coerces the argument on entry: an int cell reaches `string &$x` as "3".
$m = [3, 'z'];
app($m[0]); app($m[1]); var_dump($m);
