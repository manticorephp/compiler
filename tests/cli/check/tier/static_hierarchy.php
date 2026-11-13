<?php
abstract class Uid {
    abstract public static function fromString(string $s): static;
    public static function fromBinary(string $b): static { return static::fromString($b); }
}
final class U extends Uid { public static function fromString(string $s): static { return new U(); } }
final class Image { public static function fromString(string $s): Image { return new Missing\Gd(); } }
var_dump(U::fromBinary('x') instanceof U);
