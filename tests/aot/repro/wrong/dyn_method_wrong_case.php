<?php
// `$o->$m()` throws "undefined method" when the runtime method name differs in case from the declaration; php resolves it case-insensitively.
// issue: #581
class Multi {
    public function Zed() { return 'Zed'; }
    public function alpha() { return 'alpha'; }
    public function BetaX() { return 'BetaX'; }
    public function mid() { return 'mid'; }
}
$o = new Multi();
foreach (['Zed', 'zed', 'ALPHA', 'betax'] as $m) {
    try { echo $m, '=', $o->$m(), "\n"; } catch (Error $e) { echo $m, ' ERR ', $e->getMessage(), "\n"; }
}
