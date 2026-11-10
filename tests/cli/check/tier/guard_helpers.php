<?php
function has_apcu(): bool { return extension_loaded('apcu'); }
function two_steps(): bool { $x = 1; return extension_loaded('apcu'); }
function nested_helper(): bool { return has_apcu(); }
final class Cache {
    public static function isSupported(): bool { return \function_exists('apcu_fetch') && \apcu_enabled(); }
    public function supportsX(): bool { return class_exists('Missing\Probe'); }
    public function get() {
        if (self::isSupported()) { return apcu_fetch_m(); }
        if (!static::isSupported()) { return null; }
        return apcu_second();
    }
    public function put() {
        return $this->supportsX() ? new Missing\ViaThis() : null;
    }
}
function viaFn() { return has_apcu() ? missing_via_fn() : null; }
function viaVar(string $c) {
    $ok = class_exists($c);
    if ($ok) { new Missing\M(); }
    $both = has_apcu();
    return $both && missing_both();
}
function notHelper() { return two_steps() ? missing_two() : null; }
function notNested() { return nested_helper() ? missing_nested() : null; }
function taintedVar(string $c, bool $p) {
    $ok = class_exists($c);
    $ok = $p;
    if ($ok) { new Missing\Tainted(); }
    $q = class_exists($c);
    $q++;
    if ($q) { new Missing\Incr(); }
    $r = class_exists($c);
    foreach ([1] as $r) { }
    if ($r) { new Missing\Loop(); }
    if ($p) { new Missing\Param(); }
}
(new Cache())->get(); (new Cache())->put();
viaFn(); viaVar('x'); notHelper(); notNested(); taintedVar('x', true);
