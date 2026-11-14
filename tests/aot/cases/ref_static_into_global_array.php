<?php
// A reference to a static local stored in a global array element and read back on
// later calls (WordPress get_translations_for_domain(): `$l10n[$domain] = &$noop`).
class Noop { public function translate($t) { return $t; } }
class MO { public function translate($t) { return strtoupper($t); } }
function get_tr($domain) {
    global $l10n;
    if (isset($l10n[$domain])) { return $l10n[$domain]; }
    static $noop = null;
    if (null === $noop) { $noop = new Noop(); }
    $l10n[$domain] = &$noop;
    return $noop;
}
function translate($text, $domain = 'default') { return get_tr($domain)->translate($text); }
$l10n = ['mo' => new MO()];
echo translate('hello'), ' ', translate('again'), ' ', translate('x', 'mo'), "\n";
var_dump(is_object(get_tr('default')), get_tr('default') === get_tr('other'));
function counter() { global $refs; static $n = 7; $refs['n'] = &$n; $n++; }
counter(); counter();
var_dump($refs['n']);
