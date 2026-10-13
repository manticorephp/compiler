<?php
// A static callable array copied out of a static property default and invoked through an erased slot crashes (SIGBUS); symfony VarDumper casters.
class EC { public static function ce(string $s, int $f = 0): string { return "ce:" . $s; } }
class Cl {
    public static array $d = ["x" => ["EC", "ce"]];
    public array $casters = [];
    public function __construct() { foreach (self::$d as $t => $cb) { $this->casters[$t][] = $cb; } }
    public function cast(): string { $r = ""; foreach ($this->casters["x"] as $cb) { $r = $cb("v", 0); } return $r; }
}
$c = new Cl();
echo $c->cast(), "\n";
