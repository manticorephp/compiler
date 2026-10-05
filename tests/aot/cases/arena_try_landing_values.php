<?php

// The try landing reclaims arena memory a throw left behind. Everything still
// live at the catch, the finally or after the try must survive it: a value the
// try body built outside any loop, one built after a resetting loop finished,
// one a catch body built for its finally, and the exception's own message.
final class E extends \RuntimeException {
  public function __construct(public string $tag) { parent::__construct('m' . $tag); } }
function before(string $p): string {
  try {
    $a = $p . '-before';
    for ($j = 0; $j < 3; $j++) { $s = $p . str_repeat('x', 50); if ($j === 2) { throw new E($p . $j); } }
    return 'none';
  } catch (E $e) { return $a . '|' . $e->tag . '|' . $e->getMessage(); } }
function after(string $p): string {
  try {
    for ($j = 0; $j < 3; $j++) { $s = $p . str_repeat('y', 50); }
    $b = $p . '-after';
    $c = [$p, $p . '!'];
    throw new E('t');
  } catch (E $e) { return $b . '|' . $c[1] . '|' . $e->getMessage(); } }
function inCatch(string $p): string {
  $out = '';
  try {
    try {
      for ($j = 0; $j < 3; $j++) { $s = $p . str_repeat('z', 50); if ($j === 1) { throw new E('a'); } }
    } catch (E $e) {
      $k = $p . '-catch';
      for ($j = 0; $j < 3; $j++) { $s = $p . str_repeat('w', 50); if ($j === 1) { throw new E('b'); } }
    } finally { $out = $k . '|fin'; }
  } catch (E $e2) { $out .= '|' . $e2->tag; }
  return $out; }
function nested(string $p): string {
  $acc = '';
  for ($i = 0; $i < 3; $i++) {
    try {
      for ($j = 0; $j < 3; $j++) {
        $s = $p . str_repeat('n', 40) . $j;
        try { if ($j === 1) { throw new E('in' . $i); } } catch (E $e) { $acc .= $e->tag . ':' . strlen($s) . ','; }
        if ($j === 2 && $i === 1) { throw new E('out'); }
      }
    } catch (E $e) { $acc .= $e->tag . ';'; }
  }
  return $acc; }
function thrower(string $p): int { $s = $p . str_repeat('t', 100); throw new E(substr($s, 0, 3)); }
function viaCallee(string $p): string {
  $keep = $p . '-keep';
  try { thrower($p); } catch (E $e) { return $keep . '|' . $e->tag; }
  return 'none'; }
$r = [];
foreach (['p', 'q'] as $p) {
  $r[] = before($p); $r[] = after($p); $r[] = inCatch($p); $r[] = nested($p); $r[] = viaCallee($p);
}
echo implode("\n", $r), "\n";
