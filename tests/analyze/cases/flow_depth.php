<?php

function takesInt(int $x): int { return $x; }

class Calc
{
    public function add(int $a, int $b): int { return $a + $b; }

    public function run(string $label): void
    {
        $n = $label;              // n is string (param)
        takesInt($n);             // string given, int expected  (variable flow)
        $this->add(1, "two");     // string given, int expected  ($this method)
        echo $label - 1, "\n";    // arithmetic on string param
    }
}

$s = "hi";
takesInt($s);                     // string given, int expected

$maybe = 5;
if (strlen($s) > 0) { $maybe = "x"; }
takesInt($maybe);                 // join(int,string)=unknown -> NOT flagged

$c = new Calc();
$c->add(1, 2);                    // ok
$c->add("bad", 2);                // string given, int expected  (typed local method)
