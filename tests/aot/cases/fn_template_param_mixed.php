<?php
final class Future {
    private static bool $forced = false;
    public static function isFutureModeEnabled(): bool { return self::$forced || filter_var(getenv('PHP_CS_FIXER_FUTURE_MODE'), \FILTER_VALIDATE_BOOL); }
    /**
     * @template T
     * @param T $new
     * @param T $old
     * @return T
     */
    public static function getV4OrV3($new, $old) { return self::getNewOrOld($new, $old); }
    /**
     * @template T
     * @param T $new
     * @param T $old
     * @return T
     */
    private static function getNewOrOld($new, $old) { return self::isFutureModeEnabled() ? $new : $old; }
}
final class B {
    /** @var mixed */
    private $default;
    /** @param mixed $default */
    public function setDefault($default): self { $this->default = $default; return $this; }
    /** @return mixed */
    public function get() { return $this->default; }
}
$bs = [
    (new B())->setDefault(Future::getV4OrV3('always_last', 'always_first')),
    (new B())->setDefault(Future::getV4OrV3(true, false)),
    (new B())->setDefault(Future::getV4OrV3(['a'], ['b', 'c'])),
    (new B())->setDefault(Future::getV4OrV3(3, 4)),
    (new B())->setDefault('plain'),
];
foreach ($bs as $b) { var_dump($b->get()); }
var_dump(in_array(Future::getV4OrV3('always_last', 'always_first'), ['always_first', 'none'], true));
