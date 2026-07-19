<?php
class Reg {
    const TAG = "R";
    public static int $count = 3;
    public static function tag(): string { return "reg-tag"; }
    public static function num(): int { return 7; }
}
class Svc extends Reg {
    const TAG = "S";
    public static function tag(): string { return "svc-tag"; }
}
$o = new Reg();
echo $o::tag(), "\n";        // object receiver → static method
echo $o::num(), "\n";
echo $o::TAG, "\n";          // object receiver → class const
echo $o::$count, "\n";       // object receiver → static prop
$s = new Svc();
echo $s::tag(), "\n";        // overridden static via object
echo $s::num(), "\n";        // inherited static via object
echo $s::TAG, "\n";
$cls = "Svc";
echo $cls::tag(), "\n";      // string receiver still works
echo $cls::TAG, "\n";
