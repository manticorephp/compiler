<?php
// Property read on null SIGSEGVs instead of throwing
// issue: #18
final class M { public string $data = 'd'; }
function n(): ?M { return $GLOBALS['argc'] > 100 ? new M() : null; }
try { $v = n()->data; echo "no throw\n"; } catch (\Throwable $e) { echo "threw\n"; }
