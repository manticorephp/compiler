<?php
// A class instantiated only as `new X;` (no argument list) still gets its var_dump walk:
// the walker's reachability used to see `new X(` alone, so the dump fell through to the
// dynamic-bag walk and read a declared object as a bag (garbage, then SIGSEGV).

class Plain
{
    public int $q = 1;
    public string $name = "p";
}

class Debugged
{
    public int $hidden = 7;

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ["id" => 5, "tag" => "x"];
    }
}

class Bare {}

$p = new Plain;
var_dump($p);
unset($p);
var_dump(new Debugged);
var_dump(new Bare);
var_dump([new Plain]);
