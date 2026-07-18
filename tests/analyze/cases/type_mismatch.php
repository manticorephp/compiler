<?php

function takesInt(int $x): int { return $x; }
function takesFloat(float $x): float { return $x; }

function badReturn(): int { return "nope"; }
function goodReturn(): int { return 41; }
function widenReturn(): float { return 7; }

class Point
{
    public function __construct(int $x, int $y) {}
}

echo "5" - 1, "\n";            // arith on string
echo 10 + 2, "\n";            // ok
echo "a" . "b", "\n";         // concat ok

takesInt("str");              // string given, int expected
takesInt(3.5);                // float given, int expected
takesInt(9);                  // ok
takesInt(null);               // null given, int expected
takesFloat(3);                // ok (int widens to float)

new Point(1, "two");          // string given, int expected
new Point(1, 2);              // ok

function doThing(): void { return; }        // ok (bare return)
function badVoid(): void { return 5; }      // void must not return a value
