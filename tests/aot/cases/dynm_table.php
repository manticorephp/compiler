<?php
// A dynamic METHOD name over a receiver the compiler cannot pin down: the shape
// body dispatches on the runtime name across every class that declares one.

class Alpha {
    public function one(): string { return "alpha-one"; }
    public function two(): string { return "alpha-two"; }
    public function three(int $n): string { return "alpha-three-" . $n; }
    public function four(): string { return "alpha-four"; }
    public function five(): string { return "alpha-five"; }
}
class Beta {
    public function one(): string { return "beta-one"; }
    public function six(): string { return "beta-six"; }
    public function seven(int $n): string { return "beta-seven-" . $n; }
}

function pick(int $i): mixed { return $i === 0 ? new Alpha() : new Beta(); }

$names = ["one", "two", "four", "five", "six"];
foreach ($names as $n) {
    $o = pick(0);
    if ($n === "six") { $o = pick(1); }
    echo $o->$n(), "\n";
}

$m = "three";
$o = pick(0);
echo $o->$m(9), "\n";
$m = "seven";
$o = pick(1);
echo $o->$m(4), "\n";
