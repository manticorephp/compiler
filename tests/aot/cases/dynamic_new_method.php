<?php
class Greeter {
    public function __construct(public string $who) {}
    public function hi(): string { return "hi " . $this->who; }
}
class Plain {
    public function tag(): string { return "plain"; }
}
$cls = "Greeter";
$o = new $cls("world");
echo $o->hi(), "\n";
$p = "Plain";
$q = new $p();
echo $q->tag(), "\n";
