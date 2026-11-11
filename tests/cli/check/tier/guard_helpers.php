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
final class Polarity {
    public static function on(): bool { return function_exists('pol_probe'); }
    public function positive() {
        if (self::on()) { return pol_in_arm(); }
        return pol_rest_unguarded();
    }
    public function negated() {
        if (!self::on()) { return null; }
        return pol_rest_guarded();
    }
    public function falseCmp() {
        if (self::on() === false) { return null; }
        return pol_rest_cmp();
    }
}
function polPositive() {
    if (has_apcu()) { return 1; }
    return pol_fn_unguarded();
}
function polNegated() {
    if (!has_apcu()) { return 1; }
    return pol_fn_guarded();
}
$pl = new Polarity(); $pl->positive(); $pl->negated(); $pl->falseCmp(); polPositive(); polNegated();
function polOrOperand(string $c, bool $x) {
    if (!(has_apcu() || $x)) { return 1; }
    return pol_or_operand();
}
function polDoubleNot() {
    if (!!has_apcu()) { return 1; }
    return pol_double_not();
}
function polNotFalse() {
    if (!(has_apcu() === false)) { return 1; }
    return pol_not_false();
}
function polAndOperand(bool $x) {
    if (!(has_apcu() && $x)) { return 1; }
    return pol_and_operand();
}
polOrOperand('a', true); polDoubleNot(); polNotFalse(); polAndOperand(true);
function no_cls(): bool { return !class_exists('Missing\Probe2'); }
function mixed_g(bool $x): bool { return has_apcu() || $x; }
function polNegHelper() {
    if (!no_cls()) { return 1; }
    return pol_neg_helper();
}
function polMixedHelper() {
    if (!mixed_g(true)) { return 1; }
    return pol_mixed_helper();
}
function polNegLocal(string $c) {
    $v = !class_exists($c);
    if (!$v) { return 1; }
    return pol_neg_local();
}
function polPosLocal(string $c) {
    $v = class_exists($c);
    if (!$v) { return 1; }
    return pol_pos_local();
}
function polPosHelper() {
    if (!has_apcu()) { return 1; }
    return pol_pos_helper();
}
polNegHelper(); polMixedHelper(); polNegLocal('a'); polPosLocal('a'); polPosHelper();
