<?php
class P { public string $name = "zed"; public int $age = 40; public float $r = 2.5; }
class Q {
    public function hi(): string { return "hi-q"; }
    public function greet(): string { return "hey"; }
}
class R2 {
    public function greet(): string { return "yo-r"; }
}
// classless receiver READ
function rd(object $o, string $p) { return $o->$p; }
var_dump(rd(new P(), 'name'));
var_dump(rd(new P(), 'age'));
var_dump(rd(new P(), 'r'));
// classless receiver METHOD (agreeing return: string)
function callm(object $o, string $m) { return $o->$m(); }
echo callm(new Q(), 'hi'), "\n";
echo callm(new Q(), 'greet'), "\n";
echo callm(new R2(), 'greet'), "\n";
