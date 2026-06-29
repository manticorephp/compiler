<?php
class Base {
    public static function label(): string { return "base"; }
}
class Child extends Base {
    public static function label(): string {
        return parent::label() . "/child";
    }
}
echo Child::label();
